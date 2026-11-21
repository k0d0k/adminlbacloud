<?php

namespace App\Platform\Site\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyBookingRequestEmail;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Appointments\Exceptions\BookingSlotUnavailableException;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Models\BookingRequest;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\Appointments\Services\BranchAvailability;
use Modules\Patients\Models\Patient;

/**
 * Website CMS — public-frontpage visitor booking (Task 11).
 *
 * A visitor books an INTENT, never a schedule: {@see self::request()} records a
 * {@see BookingRequest} in the tenant database (status `pending_verification`)
 * and emails a signed proof-of-ownership link. Only after the visitor verifies
 * that link AND signs in / registers is the SAME request confirmed in the
 * patient portal via {@see self::convert()}, which re-validates the slot with
 * {@see BranchAvailability} before creating the real appointment through the
 * existing {@see AppointmentScheduler}.
 *
 * Everything runs against the ACTIVE tenant database: the public submit and the
 * verification link open the connection for the duration of the call and purge
 * it in a `finally`. The portal confirmation runs under the panel's already
 * verified context and therefore never rebinds the connection.
 */
final class VisitorBooking
{
    /** How long the email verification link stays valid. */
    public const VERIFICATION_TTL_HOURS = 24;

    public function __construct(
        private readonly ResolvesTenantDatabase $databases,
        private readonly BranchAvailability $availability,
        private readonly AppointmentScheduler $scheduler,
    ) {}

    /**
     * Record (or resume) a visitor booking request for one clinic.
     *
     * The submitted slot is validated UP FRONT against the branch's published
     * availability, so a forged or already-taken slot is refused before an
     * email is ever sent. A repeat submit for the SAME contact/slot is
     * idempotent: the one row is reused and a fresh verification link is issued
     * only while it is still pending verification.
     */
    public function request(
        Tenant $tenant,
        string $name,
        string $email,
        ?string $phone,
        string $branchId,
        string $serviceType,
        CarbonInterface $scheduledAt,
    ): BookingRequestOutcome {
        $email = Str::lower(trim($email));
        $name = trim($name);
        $when = Carbon::parse($scheduledAt);

        $this->databases->configure((string) $tenant->getKey());

        try {
            $branch = TenantBranch::query()->active()->whereKey($branchId)->first();

            if ($branch === null) {
                return BookingRequestOutcome::invalidBranch();
            }

            if (! $this->slotIsAvailable($branch, $when)) {
                return BookingRequestOutcome::slotUnavailable();
            }

            $token = Str::random(64);
            $expiresAt = now()->addHours(self::VERIFICATION_TTL_HOURS);

            $booking = BookingRequest::query()->firstOrCreate(
                [
                    'email' => $email,
                    'branch_id' => (string) $branch->getKey(),
                    'scheduled_at' => $when,
                ],
                [
                    'full_name' => $name !== '' ? $name : $email,
                    'phone' => $phone,
                    'service_type' => $serviceType,
                    'status' => BookingRequest::STATUS_PENDING_VERIFICATION,
                    'verification_token_hash' => hash('sha256', $token),
                    'verification_expires_at' => $expiresAt,
                    'privacy_consent_at' => now(),
                ],
            );

            if (! $booking->wasRecentlyCreated) {
                // A repeat submit for the same contact/slot: re-issue a fresh
                // token only while the request still awaits verification. An
                // already-verified or converted request is never reset.
                if ($booking->status === BookingRequest::STATUS_PENDING_VERIFICATION) {
                    $booking->forceFill([
                        'verification_token_hash' => hash('sha256', $token),
                        'verification_expires_at' => $expiresAt,
                    ])->save();
                } else {
                    $token = null;
                }
            }
        } finally {
            $this->databases->forget();
        }

        if ($token !== null) {
            $this->sendVerification($tenant, $booking, $email, $token);
        }

        return BookingRequestOutcome::requested($booking);
    }

    /**
     * Redeem a verification token: prove ownership of the contact email and
     * move the request to `verified`. Fails closed on an unknown, expired or
     * already-verified token.
     */
    public function verify(Tenant $tenant, string $token): ?BookingRequest
    {
        $this->databases->configure((string) $tenant->getKey());

        try {
            $booking = BookingRequest::query()
                ->where('verification_token_hash', hash('sha256', $token))
                ->where('status', BookingRequest::STATUS_PENDING_VERIFICATION)
                ->first();

            if ($booking === null || $booking->verificationHasExpired()) {
                return null;
            }

            $booking->forceFill(['status' => BookingRequest::STATUS_VERIFIED])->save();

            return $booking;
        } finally {
            $this->databases->forget();
        }
    }

    /**
     * Confirm a verified request into a real appointment (patient portal).
     *
     * Runs under the portal's already-verified tenant context. The slot is
     * re-validated with {@see BranchAvailability} and the appointment is created
     * through {@see AppointmentScheduler}, which re-checks capacity/overlap under
     * a lock. Retrying a converted request returns the EXISTING appointment —
     * never a duplicate.
     *
     * @throws BookingSlotUnavailableException when the slot is gone or the
     *                                         request is not in a confirmable state.
     */
    public function convert(BookingRequest $booking, Patient $patient, ?User $actor = null): Appointment
    {
        if ($booking->isConverted()) {
            $existing = $booking->appointment;

            if ($existing instanceof Appointment) {
                return $existing;
            }
        }

        if (! in_array($booking->status, BookingRequest::CONFIRMABLE_STATUSES, true)) {
            throw BookingSlotUnavailableException::notConfirmable((string) $booking->status);
        }

        $branch = TenantBranch::query()->active()->whereKey($booking->branch_id)->first();

        if ($branch === null || ! $this->slotIsAvailable($branch, $booking->scheduled_at)) {
            throw BookingSlotUnavailableException::slotUnavailable();
        }

        $startsAt = $booking->scheduled_at;

        $appointment = $this->scheduler->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => (string) $branch->getKey(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes($branch->effectiveSlotMinutes()),
            'service_type' => $booking->service_type,
            'status' => Appointment::STATUS_SCHEDULED,
        ], $actor);

        $booking->forceFill([
            'status' => BookingRequest::STATUS_CONVERTED,
            'appointment_id' => $appointment->getKey(),
            'patient_id' => $patient->getKey(),
        ])->save();

        return $appointment;
    }

    /**
     * Bookable slots of one active branch on one day, for the public form.
     * Public scheduling data only — never clinical data. Fails soft (empty).
     *
     * @return list<array{starts_at: string, ends_at: string, available: bool, remaining: int|null, capacity: int|null}>
     */
    public function availableSlots(Tenant $tenant, string $branchId, CarbonInterface $date): array
    {
        $this->databases->configure((string) $tenant->getKey());

        try {
            $branch = TenantBranch::query()->active()->whereKey($branchId)->first();

            if ($branch === null) {
                return [];
            }

            return array_values(array_filter(
                $this->availability->slotsForDate($branch, $date),
                static fn (array $slot): bool => (bool) $slot['available'],
            ));
        } catch (\Throwable) {
            return [];
        } finally {
            $this->databases->forget();
        }
    }

    /**
     * Active (bookable) branches of the clinic, for the public form.
     *
     * @return Collection<int, TenantBranch>
     */
    public function activeBranches(Tenant $tenant): Collection
    {
        $this->databases->configure((string) $tenant->getKey());

        try {
            return TenantBranch::query()->active()->orderBy('name')->get();
        } catch (\Throwable) {
            return collect();
        } finally {
            $this->databases->forget();
        }
    }

    private function slotIsAvailable(TenantBranch $branch, CarbonInterface $when): bool
    {
        $target = Carbon::parse($when);

        foreach ($this->availability->slotsForDate($branch, $target) as $slot) {
            if (Carbon::parse($slot['starts_at'])->equalTo($target)) {
                return (bool) $slot['available'];
            }
        }

        return false;
    }

    private function sendVerification(Tenant $tenant, BookingRequest $booking, string $email, string $token): void
    {
        $url = URL::temporarySignedRoute('tenant.booking.verify', now()->addHours(self::VERIFICATION_TTL_HOURS), [
            'slug' => (string) $tenant->slug,
            'token' => $token,
        ]);

        Notification::route('mail', $email)->notify(
            new VerifyBookingRequestEmail($booking, (string) $tenant->name, $url),
        );
    }
}
