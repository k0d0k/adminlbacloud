<?php

namespace App\Filament\Patient\Pages;

use App\Filament\Patient\Pages\Concerns\AuthorizesPatientPortal;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\Site\Services\VisitorBooking;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Modules\Appointments\Exceptions\BookingSlotUnavailableException;
use Modules\Appointments\Models\BookingRequest;
use Throwable;

/**
 * Website CMS (Task 11) — "Permintaan Booking".
 *
 * Lists the acting identity's OWN verified booking requests (matched by their
 * account email, in the verified tenant database) and lets them confirm one
 * WITHOUT re-entering the branch, service or slot they already chose on the
 * public form. Confirmation re-validates the slot and creates the appointment
 * through the SAME {@see VisitorBooking} service the public flow feeds, so the
 * lifecycle rules are enforced identically and a retry never books twice.
 */
class MyBookingRequests extends Page
{
    use AuthorizesPatientPortal;

    protected static ?string $slug = 'permintaan-booking';

    protected static ?int $navigationSort = 13;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected string $view = 'filament.patient.pages.my-booking-requests';

    public static function canAccess(): bool
    {
        return static::hasPatientContext() && static::allows(PermissionKey::BOOKING_OWN);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.patient.booking_requests');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.patient.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.patient.booking_requests.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.patient.booking_requests.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.patient.booking_requests.subtitle');
    }

    /**
     * The acting identity's OWN verified booking requests, soonest first. Fails
     * closed: a missing identity or tenant yields an empty list, never a leak.
     *
     * @return Collection<int, BookingRequest>
     */
    public function requests(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return collect();
        }

        try {
            return BookingRequest::query()
                ->with('branch')
                ->confirmable()
                ->forEmail((string) $user->email)
                ->orderBy('scheduled_at')
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    public function confirm(string $bookingRequestId): void
    {
        $patient = $this->requirePatient();

        if (! static::allows(PermissionKey::BOOKING_OWN)) {
            abort(403);
        }

        $user = auth()->user();

        // Self-only: only a request for the acting identity's OWN email is ever
        // loaded, so a foreign id resolves to nothing. An ALREADY converted
        // request is loaded too, so a retry is answered idempotently by
        // {@see VisitorBooking::convert()} instead of erroring.
        $booking = BookingRequest::query()
            ->whereIn('status', [BookingRequest::STATUS_VERIFIED, BookingRequest::STATUS_CONVERTED])
            ->forEmail($user instanceof User ? (string) $user->email : '')
            ->whereKey($bookingRequestId)
            ->first();

        abort_if($booking === null, 404);

        try {
            app(VisitorBooking::class)->convert($booking, $patient, $user instanceof User ? $user : null);
        } catch (BookingSlotUnavailableException) {
            Notification::make()
                ->danger()
                ->title(__('lba.patient.booking_requests.errors.slot_unavailable'))
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('lba.patient.booking_requests.confirmed'))
            ->send();
    }
}
