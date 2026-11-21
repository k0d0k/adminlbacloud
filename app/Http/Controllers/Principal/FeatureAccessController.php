<?php

namespace App\Http\Controllers\Principal;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Filament\Principal\Pages\FeatureAccess;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RbacCatalog;
use App\Platform\ModuleRegistry\ModuleAdministration;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 5 — Principal "Hak Akses Fitur" (feature access) TailAdmin surface.
 *
 * Replaces the interim Filament {@see FeatureAccess}
 * page. It manages the access a tenant actually holds using ONLY the existing
 * platform machinery: module ENTITLEMENTS (`module_entitlements`, the same
 * grants the FeatureGate reads) and FEATURE PERMISSIONS expressed through the
 * existing RBAC roles on tenant memberships — no parallel permission system is
 * introduced.
 *
 * Access and every mutation are re-derived server side from the acting
 * principal's `platform.entitlement.manage` permission. A membership is always
 * resolved THROUGH the tenant, so a record belonging to another partner can
 * never be mutated through this page.
 */
final class FeatureAccessController extends Controller
{
    use AuthorizesPrincipalResources;

    public function index(Request $request): View
    {
        $this->authorizeManage();

        $tenants = Tenant::query()->orderBy('name')->pluck('name', 'id')->all();
        $tenantId = (string) $request->string('tenant')->toString();
        $tenant = $tenantId !== '' ? Tenant::query()->find($tenantId) : null;

        $entitlements = [];
        $memberships = collect();

        if ($tenant instanceof Tenant) {
            $admin = app(ModuleAdministration::class);

            foreach ($admin->installedModules() as $module) {
                $key = (string) $module->module_key;
                $entitlement = $admin->entitlementFor($key, (string) $tenant->getKey());

                $entitlements[] = [
                    'key' => $key,
                    'name' => (string) $module->name,
                    'granted' => $entitlement?->isGranted() === true,
                ];
            }

            $memberships = TenantMembership::query()
                ->where('tenant_id', $tenant->getKey())
                ->with(['user', 'roles'])
                ->get();
        }

        return view('principal.entitlements.index', [
            'tenants' => $tenants,
            'tenant' => $tenant,
            'entitlements' => $entitlements,
            'memberships' => $memberships,
            'assignableRoles' => RbacCatalog::membershipAssignableRoles(),
            'roleMatrix' => RbacCatalog::rolePermissions(),
        ]);
    }

    public function grant(Request $request, Tenant $tenant, ModuleAdministration $admin): RedirectResponse
    {
        $this->authorizeManage();

        $moduleKey = $this->validatedModuleKey($request);

        $granted = $admin->grantEntitlement($moduleKey, (string) $tenant->getKey(), $this->actor());

        return to_route('principal.tailadmin.entitlements.index', ['tenant' => $tenant->getKey()])
            ->with('success', __($granted ? 'lba.feature_access.granted' : 'lba.feature_access.grant_denied'));
    }

    public function revoke(Request $request, Tenant $tenant, ModuleAdministration $admin): RedirectResponse
    {
        $this->authorizeManage();

        $moduleKey = $this->validatedModuleKey($request);

        $admin->revokeEntitlement($moduleKey, (string) $tenant->getKey(), $this->actor());

        return to_route('principal.tailadmin.entitlements.index', ['tenant' => $tenant->getKey()])
            ->with('success', __('lba.feature_access.revoked'));
    }

    public function saveRoles(Request $request, Tenant $tenant, TenantMembership $membership): RedirectResponse
    {
        $this->authorizeManage();

        // The membership must belong to the selected tenant — never trust a
        // client-supplied id across tenants.
        abort_unless((string) $membership->tenant_id === (string) $tenant->getKey(), 404);

        $data = $request->validate([
            'role_keys' => ['nullable', 'array'],
            'role_keys.*' => ['string', Rule::in(RbacCatalog::membershipAssignableRoles())],
        ]);

        $keys = array_values(array_filter((array) ($data['role_keys'] ?? []), 'is_string'));

        // Requirement 3.b / 5 — the acting principal may only assign roles whose
        // action level (CRU/CRUD) they themselves hold; a CRU principal can
        // never escalate a member to a CRUD role.
        if (! $this->mayAssignAll($keys)) {
            return to_route('principal.tailadmin.entitlements.index', ['tenant' => $tenant->getKey()])
                ->withErrors(['role_keys' => __('lba.feature_access.roles_escalation_denied')]);
        }

        $ids = Role::query()->whereIn('key', $keys)->pluck('id')->all();

        $membership->roles()->sync($ids);

        return to_route('principal.tailadmin.entitlements.index', ['tenant' => $tenant->getKey()])
            ->with('success', __('lba.feature_access.roles_updated'));
    }

    /**
     * @param  list<string>  $roleKeys
     */
    private function mayAssignAll(array $roleKeys): bool
    {
        $actor = auth()->user();

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

    private function validatedModuleKey(Request $request): string
    {
        $data = $request->validate([
            'module_key' => ['required', 'string', Rule::in($this->installedModuleKeys())],
        ]);

        return (string) $data['module_key'];
    }

    /**
     * @return list<string>
     */
    private function installedModuleKeys(): array
    {
        return app(ModuleAdministration::class)
            ->installedModules()
            ->map(static fn ($module): string => (string) $module->module_key)
            ->values()
            ->all();
    }

    private function authorizeManage(): void
    {
        abort_unless(self::principalCan(PermissionKey::PLATFORM_ENTITLEMENT_MANAGE), 403);
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
