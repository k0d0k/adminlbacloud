<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Website CMS — "check your email" notice for a patient whose account exists
 * but whose email is not yet verified.
 *
 * Public and tenant-less by design: it renders the same message for every
 * visitor and reveals nothing about whether any account exists.
 */
final class PatientVerificationNoticeController extends Controller
{
    public function __invoke(Request $request): View
    {
        $slug = (string) $request->query('tenant', '');
        $tenant = $slug === ''
            ? null
            : Tenant::query()->where('slug', $slug)->where('status', Tenant::STATUS_ACTIVE)->first();

        return view('site.verify-notice', [
            'tenant' => $tenant,
        ]);
    }
}
