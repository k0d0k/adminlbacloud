<?php

namespace Modules\Consultations\Services;

use App\Models\User;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientAuditLog;
use Modules\Patients\Models\PatientConsent;

/**
 * Workstream 2 — the patient SINA AI-assistance consent gate.
 *
 * The single place that records and reads the explicit `ai_assistance` consent,
 * so the patient panel and the patient REST API can never drift apart. A
 * recorded consent is the ONLY thing that lets a patient turn reach SINA — the
 * chat service re-derives it on every turn.
 *
 * Records live in the tenant's isolated clinical database and every grant is
 * appended to the patient audit trail with its source.
 */
final class SinaConsentService
{
    /** The consent version stamped on newly recorded grants. */
    public const VERSION = 'v1';

    /**
     * True only for an EXPLICIT, un-withdrawn `ai_assistance` consent.
     */
    public function hasConsent(Patient $patient): bool
    {
        return $this->hasConsentOfType($patient, PatientConsent::TYPE_AI_ASSISTANCE);
    }

    /**
     * True only for an EXPLICIT, un-withdrawn `expert_sharing` consent — the
     * patient's approval to share the SINA session with a human expert.
     */
    public function hasExpertSharingConsent(Patient $patient): bool
    {
        return $this->hasConsentOfType($patient, PatientConsent::TYPE_EXPERT_SHARING);
    }

    private function hasConsentOfType(Patient $patient, string $type): bool
    {
        return PatientConsent::query()
            ->where('patient_id', $patient->getKey())
            ->where('consent_type', $type)
            ->where('status', PatientConsent::STATUS_GRANTED)
            ->whereNull('withdrawn_at')
            ->exists();
    }

    /**
     * Record the patient's explicit AI-assistance consent and audit it.
     *
     * @param  string  $source  the surface that collected it (e.g. `patient_sina_chat`, `patient_api`)
     */
    public function grant(Patient $patient, ?User $actor, string $source): PatientConsent
    {
        return $this->grantType($patient, $actor, $source, PatientConsent::TYPE_AI_ASSISTANCE);
    }

    /**
     * Record the patient's explicit consent to share the SINA session with a
     * human expert and audit it.
     *
     * @param  string  $source  the surface that collected it (e.g. `patient_sina_chat`, `clinic_escalation`)
     */
    public function grantExpertSharing(Patient $patient, ?User $actor, string $source): PatientConsent
    {
        return $this->grantType($patient, $actor, $source, PatientConsent::TYPE_EXPERT_SHARING);
    }

    private function grantType(Patient $patient, ?User $actor, string $source, string $type): PatientConsent
    {
        $consent = PatientConsent::query()->create([
            'patient_id' => $patient->getKey(),
            'consent_type' => $type,
            'version' => self::VERSION,
            'status' => PatientConsent::STATUS_GRANTED,
            'recorded_by' => $actor?->getKey(),
            'recorded_at' => now(),
        ]);

        PatientAuditLog::query()->create([
            'patient_id' => $patient->getKey(),
            'action' => PatientAuditLog::ACTION_CONSENT_RECORDED,
            'actor_id' => $actor?->getKey(),
            'changes' => [
                'consent_id' => $consent->getKey(),
                'type' => $consent->consent_type,
                'source' => $source,
            ],
            'occurred_at' => now(),
        ]);

        return $consent;
    }
}
