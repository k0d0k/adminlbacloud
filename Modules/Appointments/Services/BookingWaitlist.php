<?php

namespace Modules\Appointments\Services;

use App\Models\User;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Modules\Appointments\Exceptions\WaitlistException;
use Modules\Appointments\Models\WaitlistEntry;
use Modules\Patients\Models\Patient;

/**
 * Phase 10 — booking waitlist.
 *
 * When a location is at capacity a patient can keep their place in a queue
 * instead of being refused. The queue is per location, ordered by arrival; a
 * freed slot is OFFERED to the head of the queue and must be claimed before it
 * expires, otherwise the offer lapses and the place moves on.
 *
 * Every mutation runs on the verified tenant database (fail-closed) so a
 * waitlist entry can never leak across tenants.
 */
final class BookingWaitlist
{
    use RunsVerifiedTenantTransactions;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function join(string $branchId, string $patientId, array $attributes = [], ?User $actor = null): WaitlistEntry
    {
        return $this->tenantTransaction(function () use ($branchId, $patientId, $attributes, $actor): WaitlistEntry {
            if (! TenantBranch::query()->whereKey($branchId)->exists()) {
                throw WaitlistException::unknownBranch();
            }

            if (! Patient::query()->whereKey($patientId)->exists()) {
                throw WaitlistException::unknownPatient();
            }

            $alreadyWaiting = WaitlistEntry::query()
                ->where('branch_id', $branchId)
                ->where('patient_id', $patientId)
                ->whereIn('status', WaitlistEntry::ACTIVE_STATUSES)
                ->exists();

            if ($alreadyWaiting) {
                throw WaitlistException::alreadyWaiting();
            }

            $entry = new WaitlistEntry;
            $entry->fill($attributes + [
                'branch_id' => $branchId,
                'patient_id' => $patientId,
                'status' => WaitlistEntry::STATUS_WAITING,
            ]);
            $entry->created_by = $actor?->getKey();
            $entry->save();

            return $entry;
        }, 3);
    }

    /**
     * The 1-based place in the queue (arrival order) for a branch.
     */
    public function position(WaitlistEntry $entry): int
    {
        $ahead = WaitlistEntry::query()
            ->where('branch_id', $entry->branch_id)
            ->where('status', WaitlistEntry::STATUS_WAITING)
            ->where(function ($query) use ($entry): void {
                $query->where('created_at', '<', $entry->created_at)
                    ->orWhere(function ($tie) use ($entry): void {
                        $tie->where('created_at', '=', $entry->created_at)
                            ->where('id', '<', $entry->getKey());
                    });
            })
            ->count();

        return $ahead + 1;
    }

    /**
     * Offer a freed slot to the head of the queue.
     */
    public function offer(WaitlistEntry $entry, int $minutes = 30, ?User $actor = null): WaitlistEntry
    {
        return $this->tenantTransaction(function () use ($entry, $minutes): WaitlistEntry {
            if (! $entry->isActive()) {
                throw WaitlistException::notActive();
            }

            $entry->forceFill([
                'status' => WaitlistEntry::STATUS_OFFERED,
                'offered_at' => now(),
                'expires_at' => now()->addMinutes(max(1, $minutes)),
            ])->save();

            return $entry;
        });
    }

    /**
     * Mark an offer as taken up (the caller creates the real appointment).
     */
    public function markConverted(WaitlistEntry $entry): WaitlistEntry
    {
        return $this->tenantTransaction(function () use ($entry): WaitlistEntry {
            if (! $entry->offerIsLive()) {
                throw WaitlistException::notActive();
            }

            $entry->forceFill(['status' => WaitlistEntry::STATUS_CONVERTED])->save();

            return $entry;
        });
    }

    public function cancel(WaitlistEntry $entry): WaitlistEntry
    {
        return $this->tenantTransaction(function () use ($entry): WaitlistEntry {
            if (! $entry->isActive()) {
                throw WaitlistException::notActive();
            }

            $entry->forceFill(['status' => WaitlistEntry::STATUS_CANCELLED])->save();

            return $entry;
        });
    }

    /**
     * Lapse every offer whose deadline has passed.
     *
     * @return int the number of offers expired
     */
    public function expireOffers(): int
    {
        return $this->tenantTransaction(function (): int {
            return WaitlistEntry::query()
                ->where('status', WaitlistEntry::STATUS_OFFERED)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->update(['status' => WaitlistEntry::STATUS_EXPIRED]);
        });
    }
}
