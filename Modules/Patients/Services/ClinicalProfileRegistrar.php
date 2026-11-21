<?php

namespace Modules\Patients\Services;

use App\Models\User;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Patients\Models\ClinicalAuditLog;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientAllergy;
use Modules\Patients\Models\PatientCondition;
use Modules\Patients\Models\PatientHealthHistory;
use Modules\Patients\Models\PatientMedication;

/**
 * Writes the longitudinal health profile — conditions, allergies, medications
 * and supporting history (Phase 2).
 *
 * Every entry is created with an explicit provenance (`source`) and always
 * starts UNVERIFIED: nothing becomes clinically "confirmed" merely because it
 * was typed. An authorized person promotes it with {@see self::verify()},
 * which records who reviewed it and when.
 *
 * The verification fields are deliberately NOT mass-assignable on the models,
 * so they can only be set here, inside a verified tenant transaction, with an
 * audit entry. The overall three-valued allergy status likewise lives on the
 * patient and is set only through {@see self::setAllergyStatus()}.
 */
final class ClinicalProfileRegistrar
{
    use RunsVerifiedTenantTransactions;

    /** Stable subject slugs recorded in the audit trail. */
    public const SUBJECT_CONDITION = 'patient_condition';

    public const SUBJECT_ALLERGY = 'patient_allergy';

    public const SUBJECT_MEDICATION = 'patient_medication';

    public const SUBJECT_HISTORY = 'patient_health_history';

    public function __construct(private readonly ClinicalAuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addCondition(Patient $patient, array $attributes, ?User $actor = null): PatientCondition
    {
        return $this->create($patient, new PatientCondition, self::SUBJECT_CONDITION, $attributes, $actor);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateCondition(PatientCondition $condition, array $attributes, ?User $actor = null): PatientCondition
    {
        return $this->revise($condition, $attributes, $actor);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addAllergy(Patient $patient, array $attributes, ?User $actor = null): PatientAllergy
    {
        return $this->create($patient, new PatientAllergy, self::SUBJECT_ALLERGY, $attributes, $actor);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateAllergy(PatientAllergy $allergy, array $attributes, ?User $actor = null): PatientAllergy
    {
        return $this->revise($allergy, $attributes, $actor);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addMedication(Patient $patient, array $attributes, ?User $actor = null): PatientMedication
    {
        return $this->create($patient, new PatientMedication, self::SUBJECT_MEDICATION, $attributes, $actor);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateMedication(PatientMedication $medication, array $attributes, ?User $actor = null): PatientMedication
    {
        return $this->revise($medication, $attributes, $actor);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addHistory(Patient $patient, array $attributes, ?User $actor = null): PatientHealthHistory
    {
        return $this->create($patient, new PatientHealthHistory, self::SUBJECT_HISTORY, $attributes, $actor);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateHistory(PatientHealthHistory $history, array $attributes, ?User $actor = null): PatientHealthHistory
    {
        return $this->revise($history, $attributes, $actor);
    }

    /**
     * Mark a profile entry as reviewed and confirmed by an authorized person.
     *
     * @param  array<string, mixed>  $attributes  additional edits applied with the review
     */
    public function verify(
        PatientCondition|PatientAllergy|PatientMedication|PatientHealthHistory $entry,
        ?User $actor = null,
        array $attributes = [],
    ): PatientCondition|PatientAllergy|PatientMedication|PatientHealthHistory {
        return $this->tenantTransaction(function () use ($entry, $actor, $attributes) {
            if ($attributes !== []) {
                $entry->fill($attributes);
            }

            $entry->forceFill([
                // Read through a model that uses the trait: PHP 8.5 forbids
                // reading a trait constant directly off the trait.
                'verification_status' => PatientCondition::VERIFICATION_VERIFIED,
                'verified_by' => $actor?->getKey(),
                'verified_at' => now(),
            ])->save();

            $this->audit->record(
                $entry->patient_id,
                $this->subjectType($entry),
                $entry->getKey(),
                ClinicalAuditLog::ACTION_PROFILE_ENTRY_VERIFIED,
                $actor,
                $attributes,
            );

            return $entry;
        });
    }

    /**
     * Assert the patient-level, three-valued allergy status.
     *
     * Setting `has_allergies`, `no_known_allergies` or `unknown` is an explicit
     * clinical statement — it never follows automatically from the presence or
     * absence of allergy rows.
     */
    public function setAllergyStatus(Patient $patient, string $status, ?User $actor = null): Patient
    {
        if (! in_array($status, Patient::ALLERGY_STATUSES, true)) {
            throw new InvalidArgumentException("Unknown allergy status: {$status}");
        }

        return $this->tenantTransaction(function () use ($patient, $status, $actor): Patient {
            $patient->forceFill([
                'allergy_status' => $status,
                'allergy_status_recorded_by' => $actor?->getKey(),
                'allergy_status_recorded_at' => now(),
            ])->save();

            $this->audit->record(
                $patient->getKey(),
                'patient',
                $patient->getKey(),
                ClinicalAuditLog::ACTION_ALLERGY_STATUS_SET,
                $actor,
                ['allergy_status' => $status],
            );

            return $patient;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(
        Patient $patient,
        TenantModel $entry,
        string $subjectType,
        array $attributes,
        ?User $actor,
    ): TenantModel {
        return $this->tenantTransaction(function () use ($patient, $entry, $subjectType, $attributes, $actor) {
            $entry->fill($attributes);
            $entry->patient_id = $patient->getKey();
            $entry->recorded_by = $actor?->getKey();
            $entry->save();

            $this->audit->record(
                $patient->getKey(),
                $subjectType,
                $entry->getKey(),
                ClinicalAuditLog::ACTION_PROFILE_ENTRY_CREATED,
                $actor,
                $this->auditChanges($attributes),
            );

            return $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function revise(TenantModel $entry, array $attributes, ?User $actor): TenantModel
    {
        return $this->tenantTransaction(function () use ($entry, $attributes, $actor) {
            $entry->fill($attributes)->save();

            $this->audit->record(
                $entry->patient_id,
                $this->subjectType($entry),
                $entry->getKey(),
                ClinicalAuditLog::ACTION_PROFILE_ENTRY_UPDATED,
                $actor,
                $this->auditChanges($attributes),
            );

            return $entry;
        });
    }

    private function subjectType(TenantModel $entry): string
    {
        return match (true) {
            $entry instanceof PatientCondition => self::SUBJECT_CONDITION,
            $entry instanceof PatientAllergy => self::SUBJECT_ALLERGY,
            $entry instanceof PatientMedication => self::SUBJECT_MEDICATION,
            $entry instanceof PatientHealthHistory => self::SUBJECT_HISTORY,
            default => Str::snake(class_basename($entry)),
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function auditChanges(array $attributes): array
    {
        // Only the fields the operator explicitly recorded are kept in the
        // audit trail — never derived values, never anything sensitive.
        return array_diff_key($attributes, array_flip(['patient_id']));
    }
}
