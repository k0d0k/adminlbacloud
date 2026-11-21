<?php

use App\Filament\Auth\StaffLogin;
use App\Filament\Shared\Middleware\CaptureTenantLoginHint;
use App\Http\Controllers\Account\AccountPhotoController;
use App\Http\Controllers\Account\EmailVerificationController;
use App\Http\Controllers\BookingVerificationController;
use App\Http\Controllers\Clinic\TenantShortcutController;
use App\Http\Controllers\PatientEmailVerificationController;
use App\Http\Controllers\PatientRegistrationController;
use App\Http\Controllers\PatientVerificationNoticeController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\SiteMediaPublicController;
use App\Http\Controllers\Staff\ForgotPasswordController;
use App\Http\Controllers\Staff\PasswordResetController;
use App\Http\Controllers\Staff\StaffInvitationController;
use App\Http\Controllers\VisitorBookingController;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Illuminate\Support\Facades\Route;

/*
 |--------------------------------------------------------------------------
 | Public landing page
 |--------------------------------------------------------------------------
 |
 | The single public entry point for LBA Cloud. Renders from the platform CMS
 | (central DB) and falls back to the static landing page when no published
 | version exists.
 |
 */
Route::get('/', [PublicSiteController::class, 'showPlatform'])->name('home');

/*
 |--------------------------------------------------------------------------
 | Unified staff authentication (Phase 1B-1)
 |--------------------------------------------------------------------------
 |
 | Principal and Clinic staff share this single entry point. The page is a
 | Filament page rendered inside a real panel context, so the approved LBA
 | Cloud theme, form components and premium login layout are reused verbatim.
 | `panel:principal` only supplies that rendering context — the identity's
 | permitted panel is resolved server-side after authentication.
 |
 | The patient boundary stays separate at `/patient/login`.
 */
Route::get('/login', StaffLogin::class)
    ->middleware([
        CaptureTenantLoginHint::class,
        'panel:principal',
        DispatchServingFilamentEvent::class,
    ])
    ->name('login');

/*
 |--------------------------------------------------------------------------
 | Staff invitation acceptance (WS4 P0)
 |--------------------------------------------------------------------------
 |
 | The invited staff identity opens the single-use link a Principal shared,
 | chooses their own password and activates the membership. This is a public,
 | panel-less and tenant-less boundary; once activated the account signs in
 | through the unified `/login` above.
 |
 */
Route::get('/staff/invitation/{token}', [StaffInvitationController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('staff.invitation.show');

Route::post('/staff/invitation/{token}', [StaffInvitationController::class, 'accept'])
    ->middleware('throttle:10,1')
    ->name('staff.invitation.accept');

/*
 |--------------------------------------------------------------------------
 | Password recovery (WS6.D)
 |--------------------------------------------------------------------------
 |
 | Public, panel-less and tenant-less recovery for every human identity
 | (Principal, Clinic staff and Patient). The one-time token is delivered to
 | the account's OWN verified email only — an initiating Super Admin never
 | learns or sets the new password. Both split token delivery and redemption
 | are throttled; the redeem endpoint fails closed on an unknown, expired,
 | replayed or suspended-account token.
 |
 */
Route::get('/staff/password/forgot', [ForgotPasswordController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('staff.password.forgot');

Route::post('/staff/password/forgot', [ForgotPasswordController::class, 'send'])
    ->middleware('throttle:10,1')
    ->name('staff.password.email');

Route::get('/staff/password/reset/{token}', [PasswordResetController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('staff.password.reset');

Route::post('/staff/password/reset/{token}', [PasswordResetController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('staff.password.reset.store');

/*
 |--------------------------------------------------------------------------
 | Account login-email verification (WS9.E)
 |--------------------------------------------------------------------------
 |
 | A signed, short-lived link delivered to a REQUESTED new login email. Opening
 | it proves ownership of that mailbox and only THEN switches the account's login
 | email; the previous address stays authoritative until this succeeds. The
 | controller fails closed on an unknown user or a mismatched pending address.
 |
 */
Route::get('/account/email/verify/{user}', EmailVerificationController::class)
    ->middleware(['signed', 'throttle:30,1'])
    ->name('account.email.verify');

/*
 |--------------------------------------------------------------------------
 | Account profile photo (WS9.E)
 |--------------------------------------------------------------------------
 |
 | A private, authenticated streaming endpoint for the SIGNED-IN account's own
 | photo. The identity comes from the session only, so it can never be used to
 | read another account's photo, and the file never has a public URL.
 |
 */
Route::get('/account/photo', AccountPhotoController::class)
    ->middleware('auth')
    ->name('account.photo');

/*
 |--------------------------------------------------------------------------
 | Tenant (clinic) public frontpage
 |--------------------------------------------------------------------------
 |
 | Every clinic has a fallback public URL at /klinik/{slug}. The active tenant
 | is resolved from the central registry, its database connection is opened,
 | and published CMS content is read from that tenant's database. A verified
 | custom domain can also serve this frontpage at root (see domain resolver).
 |
 */
Route::get('/klinik/{slug}', [PublicSiteController::class, 'showTenant'])
    ->middleware('throttle:120,1')
    ->name('tenant.frontpage');

/*
 |--------------------------------------------------------------------------
 | Website CMS media (published-gated public serving)
 |--------------------------------------------------------------------------
 |
 | CMS media is written to a dedicated, NON web-served disk; these endpoints
 | stream an asset ONLY while its owning page is published (a draft page's media
 | is refused to the public and previewable by an authorised editor only). The
 | controller refuses any object whose disk/prefix is not the CMS media disk, so
 | a private clinical file can never be read through it.
 |
 */
Route::get('/site/media/{media}', [SiteMediaPublicController::class, 'showPlatform'])
    ->where('media', '[0-9A-Za-z]{26}')
    ->middleware('throttle:120,1')
    ->name('site.media.show');

Route::get('/klinik/{slug}/site/media/{media}', [SiteMediaPublicController::class, 'showTenant'])
    ->where('media', '[0-9A-Za-z]{26}')
    ->middleware('throttle:120,1')
    ->name('tenant.site.media.show');

/*
 |--------------------------------------------------------------------------
 | Patient self-registration from a clinic frontpage
 |--------------------------------------------------------------------------
 |
 | Public, tenant-scoped: the clinic comes from the URL slug, never the body.
 | Registration provisions a central identity + membership and a tenant patient
 | row, then requires email verification before the portal opens. The submit
 | endpoint is tightly throttled and the response is generic (no account
 | enumeration). The verification link is signed and short-lived.
 |
 */
Route::get('/klinik/{slug}/daftar', [PatientRegistrationController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('tenant.register');

Route::post('/klinik/{slug}/daftar', [PatientRegistrationController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('tenant.register.store');

Route::get('/registrasi/verifikasi-email/{user}', PatientEmailVerificationController::class)
    ->middleware(['signed:relative', 'throttle:30,1'])
    ->name('patient.register.verify');

Route::get('/registrasi/cek-email', PatientVerificationNoticeController::class)
    ->name('patient.verify.notice');

/*
 |--------------------------------------------------------------------------
 | Visitor booking from a clinic frontpage
 |--------------------------------------------------------------------------
 |
 | Public, tenant-scoped: the clinic comes from the URL slug, never the body,
 | and only PUBLIC scheduling data is offered. Submitting records a
 | `booking_requests` row (status `pending_verification`) in the tenant database
 | and emails a signed verification link; the request only becomes an
 | appointment later, in the patient portal. A signed-in patient is redirected
 | into the portal's existing booking flow instead.
 |
 */
Route::get('/klinik/{slug}/booking/verify/{token}', BookingVerificationController::class)
    ->middleware(['signed:relative', 'throttle:30,1'])
    ->name('tenant.booking.verify');

Route::get('/klinik/{slug}/booking', [VisitorBookingController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('tenant.booking');

Route::post('/klinik/{slug}/booking', [VisitorBookingController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('tenant.booking.store');

require __DIR__.'/principal.php';
require __DIR__.'/clinic.php';

/*
 |--------------------------------------------------------------------------
 | Friendly per-clinic shortcut (/{slug})
 |--------------------------------------------------------------------------
 |
 | A single-segment path such as `/nubiom` opens that partner's clinic panel.
 | Registered as a fallback so it never shadows platform entry points
 | (/principal, /clinic, /patient, /login, ...). See TenantShortcutController.
 |
 */
Route::fallback(TenantShortcutController::class);
