<?php

namespace App\Filament\Patient\Middleware;

use App\Filament\Patient\Pages\SelectTenant;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\TenantLoginHint;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 8A — server-side patient-panel context guard.
 *
 * Mirrors the Clinic tenant guard: applied to the patient panel's authenticated
 * routes (and replayed on Livewire update requests via the panel's persistent
 * middleware) so the gateway is enforced on the SERVER, never by hiding
 * navigation:
 *
 *  - a verified context (single membership auto-selected, or a prior explicit
 *    choice) passes straight through;
 *  - several eligible memberships with nothing selected is redirected to the
 *    "Pilih Klinik" screen — no tenant is ever chosen arbitrarily;
 *  - no eligible membership is denied outright: a patient identity is always
 *    scoped to a partner clinic through an active membership.
 *
 * The selection screen and the logout route are exempt so the patient can
 * choose a clinic or sign out.
 */
class EnsurePatientTenantContext
{
    public function __construct(private readonly ManagesTenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            // Guests are resolved by Filament's Authenticate middleware.
            return $next($request);
        }

        // Website CMS (Task 9) — a clinic hinted on the login URL is applied to
        // this identity ONLY after it is re-validated against its own active
        // memberships (single-use; ignored when foreign/suspended).
        app(TenantLoginHint::class)->apply($user, $request);

        // A self-registered account whose email is NOT yet verified can never
        // open the portal — email verification is the last step of provisioning.
        // The logout route stays reachable so the identity can sign out.
        if ($this->routeName($request) !== 'filament.patient.auth.logout'
            && $user->email_verified_at === null) {
            return redirect()->to(route('patient.verify.notice'));
        }

        if ($this->isExempt($request)) {
            return $next($request);
        }

        if ($this->context->hasVerifiedContext($user)) {
            return $next($request);
        }

        if ($this->context->hasMultipleEligibleMemberships($user)) {
            return redirect()->to(SelectTenant::getUrl(panel: 'patient'));
        }

        abort(403, __('lba.patient_guard.no_active_clinic'));
    }

    private function isExempt(Request $request): bool
    {
        return in_array($this->routeName($request), [
            SelectTenant::getRouteName(Filament::getPanel('patient')),
            'filament.patient.auth.logout',
        ], true);
    }

    private function routeName(Request $request): ?string
    {
        return $request->route()?->getName();
    }
}
