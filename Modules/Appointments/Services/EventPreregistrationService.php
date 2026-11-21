<?php

namespace Modules\Appointments\Services;

use App\Models\User;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Modules\Appointments\Exceptions\PreregistrationException;
use Modules\Appointments\Models\EventPreregistration;
use Modules\Patients\Models\Patient;

/**
 * Phase 10 — preregistration for tentative events.
 *
 * A tentative (unconfirmed temporary / event) location is never bookable: it
 * must never produce a falsely confirmed booking. Instead a patient registers
 * interest, which is stored and later confirmed by the organiser once the event
 * is published. Registering against an already-confirmed location is refused —
 * that path is a normal booking.
 */
final class EventPreregistrationService
{
    use RunsVerifiedTenantTransactions;

    public function register(string $branchId, string $patientId, ?string $note = null, ?User $actor = null): EventPreregistration
    {
        return $this->tenantTransaction(function () use ($branchId, $patientId, $note): EventPreregistration {
            $branch = TenantBranch::query()->whereKey($branchId)->first();

            if ($branch === null) {
                throw PreregistrationException::unknownBranch();
            }

            if (! $branch->isTentative()) {
                throw PreregistrationException::notTentative();
            }

            if (! Patient::query()->whereKey($patientId)->exists()) {
                throw PreregistrationException::unknownPatient();
            }

            $existing = EventPreregistration::query()
                ->where('branch_id', $branchId)
                ->where('patient_id', $patientId)
                ->first();

            if ($existing !== null && $existing->status !== EventPreregistration::STATUS_CANCELLED) {
                return $existing;
            }

            if ($existing !== null) {
                $existing->forceFill([
                    'status' => EventPreregistration::STATUS_REGISTERED,
                    'note' => $note,
                    'cancelled_at' => null,
                    'confirmed_at' => null,
                ])->save();

                return $existing;
            }

            $registration = new EventPreregistration;
            $registration->fill([
                'branch_id' => $branchId,
                'patient_id' => $patientId,
                'status' => EventPreregistration::STATUS_REGISTERED,
                'note' => $note,
            ]);
            $registration->save();

            return $registration;
        }, 3);
    }

    /**
     * The organiser confirms the event: every registration becomes confirmed.
     *
     * @return int the number of registrations confirmed
     */
    public function confirmForBranch(string $branchId): int
    {
        return $this->tenantTransaction(fn (): int => EventPreregistration::query()
            ->where('branch_id', $branchId)
            ->where('status', EventPreregistration::STATUS_REGISTERED)
            ->update([
                'status' => EventPreregistration::STATUS_CONFIRMED,
                'confirmed_at' => now(),
            ]));
    }

    public function cancel(EventPreregistration $registration): EventPreregistration
    {
        return $this->tenantTransaction(function () use ($registration): EventPreregistration {
            $registration->forceFill([
                'status' => EventPreregistration::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ])->save();

            return $registration;
        });
    }
}
