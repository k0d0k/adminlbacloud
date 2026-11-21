<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Settings\PlatformSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The "contact the Principal" notice shown to a Mitra whose subscription is not
 * live (requirement 3.a.ii).
 *
 * It lives OUTSIDE the Clinic gateway on purpose: the gateway redirects here the
 * moment a lapsed subscription is detected, so this page must render without a
 * ready tenant database or a live licence. It only reads the verified tenant
 * name for display and never exposes tenant data.
 */
final class SubscriptionInactiveController extends Controller
{
    public function __invoke(
        Request $request,
        ManagesTenantContext $tenants,
    ): View {
        $user = $request->user();
        $tenant = $tenants->currentTenant($user);

        return view('clinic.subscription-inactive', [
            'systemName' => app(PlatformSettings::class)->systemName(),
            'tenantName' => $tenant?->name,
        ]);
    }
}
