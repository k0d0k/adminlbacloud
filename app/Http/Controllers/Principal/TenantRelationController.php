<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Contracts\AuthorizesPrincipal;
use App\Platform\Access\Exceptions\PasswordResetException;
use App\Platform\Access\PasswordReset;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RbacCatalog;
use App\Platform\Access\RoleKey;
use App\Platform\Access\StaffInvitationOutcome;
use App\Platform\Access\StaffInviter;
use App\Platform\Access\UserCategory;
use App\Platform\Onboarding\ClinicSetupService;
use App\Platform\Settings\PlatformSettings;
use App\Platform\Subscriptions\Models\SubscriptionPlan;
use App\Platform\Subscriptions\Models\TenantSubscription;
use App\Platform\Subscriptions\SubscriptionLicensing;
use App\Platform\Tenancy\Domain\Exceptions\DomainRegistrationException;
use App\Platform\Tenancy\Domain\TenantDomainRegistry;
use App\Platform\Tenancy\Manual\ManualDatabaseConnectionProbe;
use App\Platform\Tenancy\Manual\ManualTenantDatabaseConfigurator;
use App\Platform\Tenancy\Models\TenantDatabase;
use App\Platform\Tenancy\Models\TenantDatabaseCredential;
use App\Platform\Tenancy\TenantDatabaseName;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Phase D — TailAdmin parity for the Principal tenant governance surface.
 *
 * Replaces the Filament "Edit tenant" relation managers (memberships,
 * subscriptions, brands) with a canonically-routed TailAdmin page, so a
 * principal never has to leave TailAdmin to govern a partner. Every action
 * reuses the SAME domain services and RBAC tables the Filament surface used
 * ({@see StaffInviter}, {@see SubscriptionLicensing}, {@see PasswordReset}) —
 * no parallel write path is introduced — and each write re-checks the acting
 * identity's capability server side, never trusting the rendered UI.
 *
 * Tenant-scoped children (memberships, subscriptions, brand links) are always
 * resolved through the tenant, so a record belonging to another partner can
 * never be mutated through this page.
 */
final class TenantRelationController extends Controller
{
    public function relations(
        Request $request,
        Tenant $tenant,
        AuthorizesPrincipal $principal,
        PlatformSettings $settings,
        TenantDatabaseName $names,
    ): View {
        $actor = $request->user();
        $tenantId = (string) $tenant->getKey();

        $memberships = $tenant->memberships()
            ->with(['user', 'roles'])
            ->orderBy('created_at')
            ->get();

        $subscriptions = $tenant->subscriptions()
            ->with('plan')
            ->orderByDesc('created_at')
            ->get();

        $brands = $tenant->brands()
            ->orderBy('name')
            ->get();

        $domains = $tenant->domains()
            ->orderBy('domain')
            ->get();

        // The physical database the application derives from THIS tenant's id
        // and the hosting prefix. Never taken from user input, so it can only
        // ever be this tenant's own schema — not the central database and not
        // another tenant's database.
        try {
            $databaseTarget = $names->for($tenantId);
        } catch (Throwable) {
            $databaseTarget = '';
        }

        $databaseRecord = TenantDatabase::query()->where('tenant_id', $tenantId)->first();
        $credential = TenantDatabaseCredential::query()->where('tenant_id', $tenantId)->first();

        return view('principal.tenants.relations', [
            'tenant' => $tenant,
            'memberships' => $memberships,
            'subscriptions' => $subscriptions,
            'brands' => $brands,
            'domains' => $domains,
            'candidateUsers' => $this->candidateUsers(),
            'staffRoleGroups' => UserCategory::staffInvitationGroups(),
            'assignableRoleGroups' => UserCategory::groupedRoleOptions(RbacCatalog::membershipAssignableRoles()),
            'activePlans' => SubscriptionPlan::query()
                ->where('status', SubscriptionPlan::STATUS_ACTIVE)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            'availableBrands' => Brand::query()
                ->whereDoesntHave('tenants', fn ($query) => $query->whereKey($tenant->getKey()))
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            'canManageBilling' => $actor !== null && $principal->can($actor, PermissionKey::PLATFORM_BILLING_MANAGE),
            'canViewSubscriptions' => $actor !== null && $principal->can($actor, PermissionKey::PLATFORM_SUBSCRIPTION_VIEW),
            'canManageAccess' => $actor !== null && $principal->can($actor, PermissionKey::PLATFORM_RBAC_MANAGE),
            // Tenant database actions are restricted to the Principal Super
            // Admin capability. The section is hidden for anyone else, and every
            // write re-checks it server-side.
            'canManageDatabase' => $actor !== null && $principal->can($actor, PermissionKey::PLATFORM_RBAC_MANAGE),
            'database' => [
                'target' => $databaseTarget,
                'configured_name' => trim((string) ($databaseRecord?->configured_database_name ?? '')),
                'mode' => $settings->databaseMode(),
                'state' => $this->databaseState($databaseRecord),
                'username' => $credential !== null ? trim((string) $credential->username) : '',
                'password_configured' => $credential !== null
                    && is_string($credential->password)
                    && $credential->password !== '',
            ],
            'databaseProbe' => $request->session()->get('database_probe'),
            'databaseResult' => $request->session()->get('database_result'),
        ]);
    }

    /**
     * Store the tenant's MANUAL database target and MySQL credential
     * (username + write-only password). A blank password keeps the stored value.
     * Requires the Principal Super Admin capability, re-checked server-side.
     *
     * A submitted NAME goes through the single write path
     * ({@see ManualTenantDatabaseConfigurator}), so the same fail-closed rules
     * apply here as on the onboarding form: never the central database, never a
     * name another partner already claimed, and never a silent re-point of a
     * partner whose database is already provisioned or migrated. When no name is
     * submitted (a credential-only change) the focused credential write is used.
     */
    public function saveDatabaseCredentials(
        Request $request,
        Tenant $tenant,
        ManualTenantDatabaseConfigurator $configurator,
    ): RedirectResponse {
        $this->authorizeDatabase($request);

        $data = $request->validate([
            'db_name' => ['nullable', 'string', 'max:64'],
            'db_username' => ['nullable', 'string', 'max:64'],
            'db_password' => ['nullable', 'string', 'max:255'],
        ]);

        $redirect = to_route('principal.tailadmin.tenants.relations', $tenant);
        $name = trim((string) ($data['db_name'] ?? ''));

        if ($name !== '') {
            $reason = $configurator->reason($name, $tenant);

            if ($reason !== null) {
                return $redirect->withErrors([
                    'db_name' => __('principal.tenants.database.config_error_'.$reason),
                ]);
            }

            $configurator->configure(
                $tenant,
                $name,
                $data['db_username'] ?? null,
                $data['db_password'] ?? null,
            );

            return $redirect->with('success', __('principal.tenants.database.credentials_saved'));
        }

        $credential = TenantDatabaseCredential::query()->firstOrNew(['tenant_id' => (string) $tenant->getKey()]);
        $credential->username = (string) ($data['db_username'] ?? '');

        $password = (string) ($data['db_password'] ?? '');

        if ($password !== '') {
            $credential->password = $password;
        }

        $credential->save();

        return $redirect->with('success', __('principal.tenants.database.credentials_saved'));
    }

    /**
     * READ-ONLY connection test ("Uji Koneksi"). It makes NO cPanel UAPI call,
     * runs NO DDL and NEVER migrates — it only proves reachability.
     */
    public function testDatabaseConnection(
        Request $request,
        Tenant $tenant,
        ManualDatabaseConnectionProbe $probe,
    ): RedirectResponse {
        $this->authorizeDatabase($request);

        return to_route('principal.tailadmin.tenants.relations', $tenant)
            ->with('database_probe', $probe->run((string) $tenant->getKey()));
    }

    /**
     * Run the tenant migrations ("Jalankan Migrasi") through the EXISTING tenant
     * path: the same guarded provision+migrate+register pipeline as one-click
     * setup. A registered tenant's database name is never rewritten (the
     * mismatch guard fails closed), and the registry only reaches Ready AFTER
     * the migrations succeed.
     */
    public function migrateDatabase(
        Request $request,
        Tenant $tenant,
        ClinicSetupService $setup,
    ): RedirectResponse {
        $this->authorizeDatabase($request);

        $result = $setup->provisionDatabase($tenant, $request->user());

        return to_route('principal.tailadmin.tenants.relations', $tenant)
            ->with('database_result', [
                'ready' => $result->ready,
                'error' => $result->error,
            ]);
    }

    public function assignOwner(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists(User::class, 'id')],
        ]);

        $user = User::query()->findOrFail($data['user_id']);

        $membership = TenantMembership::query()->firstOrCreate(
            ['user_id' => $user->getKey(), 'tenant_id' => $tenant->getKey()],
            ['status' => TenantMembership::STATUS_ACTIVE],
        );

        if ($membership->status !== TenantMembership::STATUS_ACTIVE) {
            $membership->update([
                'status' => TenantMembership::STATUS_ACTIVE,
                'revoked_at' => null,
            ]);
        }

        $roleId = Role::query()->where('key', RoleKey::TENANT_OWNER)->value('id');

        if ($roleId !== null && ! $membership->roles()->whereKey($roleId)->exists()) {
            $membership->roles()->attach($roleId);
        }

        return to_route('principal.tailadmin.tenants.relations', $tenant)
            ->with('success', __('lba.tenants.owner_assigned'));
    }

    public function inviteMember(Request $request, Tenant $tenant, StaffInviter $inviter): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'role_keys' => ['required', 'array'],
            'role_keys.*' => ['string'],
        ]);

        $outcome = $inviter->invite(
            $tenant,
            (string) $data['email'],
            (string) $data['name'],
            array_values(array_map('strval', (array) $data['role_keys'])),
            $request->user(),
        );

        $redirect = to_route('principal.tailadmin.tenants.relations', $tenant);

        return match ($outcome->status) {
            StaffInvitationOutcome::STATUS_INVITED => $redirect->with('success', __('lba.tenants.invited').' '.$outcome->activationUrl),
            StaffInvitationOutcome::STATUS_ALREADY_MEMBER => $redirect->withErrors(['email' => __('lba.tenants.invite_already_member')]),
            StaffInvitationOutcome::STATUS_PRINCIPAL_FORBIDDEN => $redirect->withErrors(['email' => __('lba.tenants.invite_principal_forbidden')]),
            StaffInvitationOutcome::STATUS_ESCALATION_FORBIDDEN => $redirect->withErrors(['role_keys' => __('lba.tenants.roles_escalation_denied')]),
            default => $redirect->withErrors(['email' => __('lba.tenants.invite_failed')]),
        };
    }

    /**
     * Requirement 3.b / 5 — an actor may only assign roles whose action level
     * (CRU/CRUD) they themselves hold, so a CRU principal can never grant a
     * delete-capable role.
     *
     * @param  list<string>  $roleKeys
     */
    private function mayAssignAll(?User $actor, array $roleKeys): bool
    {
        if (! $actor instanceof User) {
            return false;
        }

        $actorRoleKeys = app(\App\Platform\Access\Contracts\ResolvesRoles::class)->allActiveRoleKeys($actor);

        foreach ($roleKeys as $roleKey) {
            if (! RbacCatalog::mayGrantRole($actorRoleKeys, $roleKey)) {
                return false;
            }
        }

        return true;
    }

    public function updateMemberRoles(Request $request, Tenant $tenant, TenantMembership $membership): RedirectResponse
    {
        $this->resolveMembership($tenant, $membership);

        $data = $request->validate([
            'role_keys' => ['required', 'array'],
            'role_keys.*' => ['string', Rule::in(RbacCatalog::membershipAssignableRoles())],
        ]);

        $keys = array_values(array_map('strval', $data['role_keys']));

        // Requirement 3.b / 5 — the acting principal may only assign roles whose
        // action level (CRU/CRUD) they themselves hold.
        if (! $this->mayAssignAll($request->user(), $keys)) {
            return to_route('principal.tailadmin.tenants.relations', $tenant)
                ->withErrors(['role_keys' => __('lba.tenants.roles_escalation_denied')]);
        }

        $ids = Role::query()->whereIn('key', $keys)->pluck('id')->all();
        $membership->roles()->sync($ids);

        return to_route('principal.tailadmin.tenants.relations', $tenant)
            ->with('success', __('lba.tenants.roles_updated'));
    }

    public function updateMemberStatus(Request $request, Tenant $tenant, TenantMembership $membership): RedirectResponse
    {
        $this->resolveMembership($tenant, $membership);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                TenantMembership::STATUS_ACTIVE,
                TenantMembership::STATUS_SUSPENDED,
                TenantMembership::STATUS_REVOKED,
            ])],
        ]);

        $membership->update([
            'status' => $data['status'],
            'revoked_at' => $data['status'] === TenantMembership::STATUS_REVOKED ? now() : null,
        ]);

        return to_route('principal.tailadmin.tenants.relations', $tenant);
    }

    public function sendPasswordReset(
        Request $request,
        Tenant $tenant,
        TenantMembership $membership,
        AuthorizesPrincipal $principal,
        PasswordReset $resets,
    ): RedirectResponse {
        $this->resolveMembership($tenant, $membership);

        $actor = $request->user();
        abort_unless($actor instanceof User && $principal->can($actor, PermissionKey::PLATFORM_RBAC_MANAGE), 403);

        $target = $membership->user;
        $redirect = to_route('principal.tailadmin.tenants.relations', $tenant);

        if (! $target instanceof User) {
            return $redirect->withErrors(['email' => __('lba.password_reset.super_admin_failed')]);
        }

        try {
            $resets->request($target, $actor);
        } catch (PasswordResetException) {
            return $redirect->withErrors(['email' => __('lba.password_reset.super_admin_failed')]);
        }

        return $redirect->with('success', __('lba.password_reset.super_admin_sent_body', ['email' => (string) $target->email]));
    }

    public function subscribe(
        Request $request,
        Tenant $tenant,
        AuthorizesPrincipal $principal,
        SubscriptionLicensing $licensing,
    ): RedirectResponse {
        $this->authorizeBilling($request, $principal);

        $data = $request->validate([
            'plan_id' => ['required', 'ulid', Rule::exists(SubscriptionPlan::class, 'id')],
            'seats' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $plan = SubscriptionPlan::query()->findOrFail($data['plan_id']);

        $licensing->subscribe($tenant, $plan, (int) ($data['seats'] ?? 1), $request->user());

        return to_route('principal.tailadmin.tenants.relations', $tenant)
            ->with('success', __('lba.subscriptions.subscribed'));
    }

    public function activateSubscription(
        Request $request,
        Tenant $tenant,
        TenantSubscription $subscription,
        AuthorizesPrincipal $principal,
        SubscriptionLicensing $licensing,
    ): RedirectResponse {
        $this->resolveSubscription($tenant, $subscription);
        $this->authorizeBilling($request, $principal);

        $licensing->activate($subscription, $request->user());

        return to_route('principal.tailadmin.tenants.relations', $tenant)
            ->with('success', __('lba.subscriptions.activated'));
    }

    public function cancelSubscription(
        Request $request,
        Tenant $tenant,
        TenantSubscription $subscription,
        AuthorizesPrincipal $principal,
        SubscriptionLicensing $licensing,
    ): RedirectResponse {
        $this->resolveSubscription($tenant, $subscription);
        $this->authorizeBilling($request, $principal);

        $licensing->cancel($subscription);

        return to_route('principal.tailadmin.tenants.relations', $tenant)
            ->with('success', __('lba.subscriptions.cancelled'));
    }

    public function attachBrand(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'brand_id' => ['required', 'ulid', Rule::exists(Brand::class, 'id')],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $brand = Brand::query()->findOrFail($data['brand_id']);

        $tenant->getConnection()->transaction(function () use ($tenant, $brand, $data): void {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->getKey());
            $tenant->brands()->syncWithoutDetaching([$brand->getKey()]);

            if ($data['is_primary'] ?? false) {
                $this->makePrimary($tenant, $brand);
            }
        }, 3);

        return to_route('principal.tailadmin.tenants.relations', $tenant);
    }

    public function primaryBrand(Tenant $tenant, Brand $brand): RedirectResponse
    {
        $this->ensureBrandLinked($tenant, $brand);

        $tenant->getConnection()->transaction(function () use ($tenant, $brand): void {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->getKey());
            $this->makePrimary($tenant, $brand);
        }, 3);

        return to_route('principal.tailadmin.tenants.relations', $tenant)
            ->with('success', __('lba.brands.primary_set'));
    }

    public function detachBrand(Tenant $tenant, Brand $brand): RedirectResponse
    {
        $this->ensureBrandLinked($tenant, $brand);

        $tenant->getConnection()->transaction(function () use ($tenant, $brand): void {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->getKey());
            $tenant->brands()->detach($brand->getKey());
        }, 3);

        return to_route('principal.tailadmin.tenants.relations', $tenant);
    }

    /**
     * Register a PENDING custom hostname for the partner. The single write path
     * ({@see TenantDomainRegistry}) normalises the host, rejects an invalid,
     * reserved or already-claimed name, and issues a verification token — it
     * never makes the hostname load-bearing on its own.
     */
    public function storeDomain(Request $request, Tenant $tenant, TenantDomainRegistry $registry): RedirectResponse
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
        ]);

        $redirect = to_route('principal.tailadmin.tenants.relations', $tenant);

        try {
            $registry->register($tenant, (string) $data['domain']);
        } catch (DomainRegistrationException $exception) {
            return $redirect->withErrors(['domain' => __('principal.tenants.domains.error_'.$exception->errorCode())]);
        }

        return $redirect->with('success', __('principal.tenants.domains.registered'));
    }

    /**
     * Re-check ownership of the hostname against its published DNS TXT records.
     * A match marks the binding verified; otherwise the check is recorded as
     * failed and the binding stays non-load-bearing.
     */
    public function verifyDomain(
        Request $request,
        Tenant $tenant,
        TenantDomain $tenantDomain,
        TenantDomainRegistry $registry,
    ): RedirectResponse {
        $this->resolveDomain($tenant, $tenantDomain);

        $verified = $registry->verify($tenantDomain);

        return to_route('principal.tailadmin.tenants.relations', $tenant)
            ->with($verified ? 'success' : 'error', __(
                $verified ? 'principal.tenants.domains.verify_success' : 'principal.tenants.domains.verify_failed',
            ));
    }

    public function destroyDomain(Request $request, Tenant $tenant, TenantDomain $tenantDomain, TenantDomainRegistry $registry): RedirectResponse
    {
        $this->resolveDomain($tenant, $tenantDomain);

        $registry->remove($tenantDomain);

        return to_route('principal.tailadmin.tenants.relations', $tenant)
            ->with('success', __('principal.tenants.domains.removed'));
    }

    /**
     * Designate one verified domain as the tenant's canonical hostname. Only a
     * Principal Super Admin may do this — the route group's `can:update,tenant`
     * gate (PLATFORM_TENANT_MANAGE) enforces it. All other domains for the
     * tenant are demoted in a single transaction so at most one is primary.
     */
    public function setPrimaryDomain(Request $request, Tenant $tenant, TenantDomain $tenantDomain): RedirectResponse
    {
        $this->resolveDomain($tenant, $tenantDomain);

        abort_unless($tenantDomain->isVerified(), 422, __('principal.tenants.domains.primary_requires_verified'));

        DB::transaction(function () use ($tenant, $tenantDomain): void {
            TenantDomain::query()
                ->where('tenant_id', $tenant->getKey())
                ->whereKeyNot($tenantDomain->getKey())
                ->update(['is_primary' => false]);

            $tenantDomain->update(['is_primary' => true]);
        });

        return to_route('principal.tailadmin.tenants.relations', $tenant)
            ->with('success', __('principal.tenants.domains.primary_set'));
    }

    /**
     * Resolve a membership THROUGH the tenant, so a membership belonging to
     * another partner is reported as missing rather than mutated.
     */
    private function resolveMembership(Tenant $tenant, TenantMembership $membership): void
    {
        abort_unless((string) $membership->tenant_id === (string) $tenant->getKey(), 404);
    }

    /**
     * Resolve a domain registration THROUGH the tenant, so a hostname claimed by
     * another partner is reported as missing rather than mutated.
     */
    private function resolveDomain(Tenant $tenant, TenantDomain $domain): void
    {
        abort_unless((string) $domain->tenant_id === (string) $tenant->getKey(), 404);
    }

    private function resolveSubscription(Tenant $tenant, TenantSubscription $subscription): void
    {
        abort_unless((string) $subscription->tenant_id === (string) $tenant->getKey(), 404);
    }

    private function ensureBrandLinked(Tenant $tenant, Brand $brand): void
    {
        abort_unless($tenant->brands()->whereKey($brand->getKey())->exists(), 404);
    }

    private function authorizeBilling(Request $request, AuthorizesPrincipal $principal): void
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $principal->can($actor, PermissionKey::PLATFORM_BILLING_MANAGE), 403);
    }

    /**
     * Database actions are restricted to the Principal Super Admin capability
     * (`platform.rbac.manage`), re-derived server-side on every request — never
     * from UI visibility.
     */
    private function authorizeDatabase(Request $request): void
    {
        $actor = $request->user();
        abort_unless(
            $actor instanceof User
                && app(AuthorizesPrincipal::class)->can($actor, PermissionKey::PLATFORM_RBAC_MANAGE),
            403,
        );
    }

    /**
     * Project the central registry row into the four states the screen renders.
     * "Ready" is reached ONLY after a successful migration.
     *
     * @return array{state: string, reason: string}
     */
    private function databaseState(?TenantDatabase $record): array
    {
        if (! $record instanceof TenantDatabase) {
            return ['state' => 'pending', 'reason' => ''];
        }

        $state = match ($record->status) {
            TenantDatabase::STATUS_MIGRATED => 'ready',
            TenantDatabase::STATUS_FAILED => 'failed',
            TenantDatabase::STATUS_PROVISIONED => 'processing',
            default => 'pending',
        };

        return ['state' => $state, 'reason' => $state === 'failed' ? 'database_error' : ''];
    }

    private function makePrimary(Tenant $tenant, Brand $brand): void
    {
        $tenant->brands()->newPivotStatement()->where('tenant_id', $tenant->getKey())
            ->where('is_primary', true)->update(['is_primary' => false]);
        $tenant->brands()->updateExistingPivot($brand->getKey(), ['is_primary' => true]);
    }

    /**
     * Active central identities eligible to be a tenant member.
     *
     * @return array<string, string>
     */
    private function candidateUsers(): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                (string) $user->getKey() => $user->email
                    ? $user->name.' · '.$user->email
                    : (string) $user->name,
            ])
            ->all();
    }
}
