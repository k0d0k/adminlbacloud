<?php

namespace App\Filament\Clinic\Middleware;

use App\Filament\Clinic\Pages\SelectTenant;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\TenantLoginHint;
use App\Platform\Subscriptions\TenantSubscriptionGate;
use App\Platform\Tenancy\TenantDatabaseReadiness;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 1B-2B — server-side Clinic tenant context guard.
 *
 * Applied to the Clinic panel's authenticated routes (including Livewire
 * replayed persistent middleware) so the gateway is enforced at the server
 * layer, never by hiding navigation:
 *
 *  - a verified context (single membership auto-selected, or a prior explicit
 *    choice) passes straight through;
 *  - several eligible memberships with nothing selected is redirected to the
 *    "Pilih Mitra" screen — no tenant is ever chosen arbitrarily;
 *  - no eligible membership is denied outright.
 *
 * Transition note (STEP 5): `users.role` still routes an identity to the Clinic
 * panel (approving it, unchanged, so login keeps working), but it no longer
 * grants *operational* tenant context on its own. A Clinic role without an
 * active membership reaches the panel shell but is denied the dashboard.
 *
 * The selection screen and the logout route are exempt so the user can choose a
 * tenant or sign out. When a verified context exists but the tenant's isolated
 * database is not ready (provisioned-but-not-migrated, failed or dropped), the
 * request is denied with a useful message rather than failing mid-query.
 *
 * Requirement 3.a — subscription gate: when a verified context exists but the
 * tenant holds no LIVE subscription (period ended, cancelled, expired,
 * suspended), the Mitra is sent to the "hubungi Principal" notice instead of
 * the workspace. The check runs on every request, so a licence that lapses
 * mid-session closes the workspace at once rather than at next login.
 */
class EnsureClinicTenantContext
{
    public function __construct(
        private readonly ManagesTenantContext $context,
        private readonly TenantDatabaseReadiness $readiness,
        private readonly TenantSubscriptionGate $subscriptions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            // Guests are resolved by Filament's Authenticate middleware.
            return $next($request);
        }

        // Website CMS (Task 10) — apply a clinic hinted on the login URL, after
        // re-validating it against this identity's own active memberships.
        app(TenantLoginHint::class)->apply($user, $request);

        if ($this->isExempt($request)) {
            return $next($request);
        }

        if ($this->context->hasVerifiedContext($user)) {
            $tenantId = $this->context->currentTenantId($user);

            if ($tenantId !== null && ! $this->readiness->isReady($tenantId)) {
                abort(503, __('lba.tenant_guard.database_unavailable'));
            }

            // Requirement 3.a — a lapsed subscription means no workspace. The
            // Mitra keeps their session but is pointed at the notice that asks
            // them to contact the Principal; nothing tenant-scoped is served.
            // A tenant with no subscription on file is not "lapsed" and keeps
            // the existing membership + database contract.
            if ($tenantId !== null && $this->subscriptions->hasLapsed($tenantId)) {
                return redirect()->route('clinic.subscription.inactive');
            }

            return $next($request);
        }

        if ($this->context->hasMultipleEligibleMemberships($user)) {
            return redirect()->to(SelectTenant::getUrl(panel: 'clinic'));
        }

        abort(403, __('lba.tenant_guard.no_active_partner'));
    }

    private function isExempt(Request $request): bool
    {
        $name = $request->route()?->getName();

        return in_array($name, [
            SelectTenant::getRouteName(Filament::getPanel('clinic')),
            'filament.clinic.auth.logout',
            'clinic.subscription.inactive',
        ], true);
    }
}
