<?php

namespace Modules\Consultations\Services;

use App\Models\User;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Modules\Consultations\Exceptions\ConsultationException;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Models\ConsultationAuditLog;
use Modules\Patients\Models\Patient;

/**
 * Phase 13 — escalate a SINA AI chat session to a human expert consultation.
 *
 * The bridge from Phase 13's SINA chat to the EXISTING human consultation flow:
 * it creates an ordinary consultation by delegating to the SAME
 * {@see ConsultationBooking} the patient portal and API already use, so
 * scheduling, practitioner authorization, branch capacity and the disclosed
 * tariff all keep working exactly as before — nothing here re-implements them.
 *
 * Two fail-closed gates are added on top of the human flow:
 *  - the source must be a SINA AI chat session (never an ordinary encounter);
 *  - the patient must have explicitly granted the `expert_sharing` consent, i.e.
 *    approved sharing the SINA session with a person. The `ai_assistance`
 *    consent that authorised the chat itself is NOT sufficient.
 *
 * CREDIT SEPARATION: the human consultation is metered by its own channel
 * tariff ({@see ConsultationTariffService}), NEVER by the SINA per-turn credit
 * wallet. This service deliberately does not depend on the wallet, so an
 * escalation can never reserve, capture or release a single SINA credit — the
 * two credit ledgers stay independent by construction.
 */
final class SinaEscalation
{
    use RunsVerifiedTenantTransactions;

    public function __construct(
        private readonly ConsultationBooking $booking,
        private readonly SinaConsentService $consent,
    ) {}

    /**
     * Escalate a SINA session to a human expert and return the new consultation.
     *
     * @param  array<string, mixed>  $attributes  optional `type`, `channel`, `scheduled_at`, `reason`, `summary`
     *
     * @throws ConsultationException
     */
    public function escalate(
        Consultation $session,
        TenantBranch $branch,
        string $practitionerId,
        array $attributes = [],
        ?User $actor = null,
    ): Consultation {
        return $this->tenantTransaction(function () use ($session, $branch, $practitionerId, $attributes, $actor): Consultation {
            // Re-resolve the session on the VERIFIED tenant connection so a
            // foreign id can never resolve.
            $source = Consultation::query()->whereKey($session->getKey())->first();

            if ($source === null) {
                throw ConsultationException::notActive();
            }

            if (! $source->isSinaSession()) {
                throw ConsultationException::notSinaSession();
            }

            if (! $source->isActive()) {
                throw ConsultationException::notActive();
            }

            if (trim($practitionerId) === '') {
                throw ConsultationException::practitionerRequired();
            }

            $patient = Patient::query()->whereKey($source->patient_id)->first();

            if ($patient === null) {
                throw ConsultationException::unknownPatient();
            }

            // The patient must have approved SHARING the session with a human
            // expert. Escalation is refused otherwise.
            if (! $this->consent->hasExpertSharingConsent($patient)) {
                throw ConsultationException::expertSharingConsentRequired();
            }

            $human = $this->booking->book($branch, array_merge([
                'patient_id' => $source->patient_id,
                'branch_id' => $branch->getKey(),
                'channel' => Consultation::CHANNEL_TELECONSULT,
                'type' => Consultation::TYPE_CONSULTATION,
                'escalated_from_id' => $source->getKey(),
            ], $attributes), $practitionerId, $actor);

            $this->audit($human, $source, $actor);
            $this->audit($source, $human, $actor);

            return $human;
        });
    }

    private function audit(Consultation $consultation, Consultation $counterpart, ?User $actor): void
    {
        ConsultationAuditLog::query()->create([
            'consultation_id' => $consultation->getKey(),
            'action' => ConsultationAuditLog::ACTION_ESCALATED,
            'actor_id' => $actor?->getKey(),
            'changes' => [
                'counterpart_id' => $counterpart->getKey(),
                'counterpart_channel' => $counterpart->channel,
            ],
            'occurred_at' => now(),
        ]);
    }
}
