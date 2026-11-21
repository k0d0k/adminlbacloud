<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Site\Services\PatientSelfRegistration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Website CMS — redemption of a patient email-verification link (Task 8).
 *
 * The signed link was delivered to the address the visitor registered with;
 * opening it proves ownership of that mailbox. Before the portal is opened the
 * tenant patient row is re-ensured, so an interrupted provisioning can never
 * leave a half-finished account behind. Verification proves EMAIL OWNERSHIP
 * only.
 */
final class PatientEmailVerificationController extends Controller
{
    public function __invoke(
        Request $request,
        string $user,
        PatientSelfRegistration $registration,
    ): RedirectResponse {
        $account = User::query()->find($user);

        if (! $account instanceof User) {
            abort(404);
        }

        $slug = (string) $request->query('tenant', '');

        $tenant = Tenant::query()
            ->where('slug', $slug)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->first();

        if ($tenant === null) {
            abort(404);
        }

        // Only a still-unverified account is provisioned and verified here; a
        // stale link for an already-verified identity is a harmless redirect.
        if ($account->email_verified_at === null) {
            $registration->enforceProvisioned($tenant, $account);

            $account->forceFill(['email_verified_at' => now()])->save();
        }

        return redirect()
            ->to('/patient/login?tenant='.$tenant->slug)
            ->with('status', __('site.registration.verified'));
    }
}
