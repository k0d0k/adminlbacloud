<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Site\Services\VisitorBooking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Website CMS (Task 11) — redemption of a visitor's booking-verification link.
 *
 * The signed link was delivered to the email the visitor submitted; opening it
 * proves ownership of that mailbox and moves the request to `verified`. The
 * visitor is then forwarded to the appropriate continuation — sign in when an
 * account already exists for that email, otherwise register — where the SAME
 * request is confirmed without re-entering any data.
 */
final class BookingVerificationController extends Controller
{
    public function __invoke(
        Request $request,
        string $slug,
        string $token,
        VisitorBooking $booking,
    ): RedirectResponse {
        $tenant = Tenant::query()
            ->where('slug', $slug)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->first();

        if ($tenant === null) {
            abort(404);
        }

        $verified = $booking->verify($tenant, $token);

        if ($verified === null) {
            abort(404);
        }

        $hasAccount = User::query()
            ->whereRaw('lower(email) = ?', [strtolower(trim((string) $verified->email))])
            ->exists();

        $target = $hasAccount
            ? '/patient/login?tenant='.$tenant->slug
            : '/klinik/'.$tenant->slug.'/daftar';

        return redirect()->to($target)->with('status', __('booking.verified'));
    }
}
