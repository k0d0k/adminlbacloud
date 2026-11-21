<?php

namespace Modules\Patients\Services;

use App\Models\User;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Modules\Patients\Exceptions\ClinicalRecordException;
use Modules\Patients\Models\ClinicalAuditLog;
use Modules\Patients\Models\ClinicalEncounter;
use Modules\Patients\Models\ClinicalEncounterAmendment;
use Modules\Patients\Models\Patient;

/**
 * Opens, writes, finalises and amends clinical encounters (Phase 3).
 *
 * A patient has many encounters and a new one never overwrites an earlier one.
 * Lifecycle:
 *  - a DRAFT may be edited freely;
 *  - a FINAL is frozen — the only way to change it is {@see self::amend()},
 *    which requires a reason and stores the previous and new values;
 *  - every transition is written to the clinical audit trail in the same
 *    verified tenant transaction, so an audit row can never drift from the
 *    change it describes.
 *
 * The narrative fields that may be corrected by an amendment are an explicit
 * allow-list, so an amendment can never silently rewrite identity, timing or
 * sign-off metadata.
 */
final class ClinicalEncounterRegistrar
{
    use RunsVerifiedTenantTransactions;

    /** The clinical narrative an amendment may correct. */
    public const AMENDABLE_FIELDS = [
        'chief_complaint',
        'history_of_present_illness',
        'reviewed_history',
        'physical_exam',
        'supporting_findings',
        'subjective',
        'objective',
        'assessment',
        'plan',
    ];

    public function __construct(private readonly ClinicalAuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function open(Patient $patient, array $attributes, ?User $actor = null): ClinicalEncounter
    {
        return $this->tenantTransaction(function () use ($patient, $attributes, $actor): ClinicalEncounter {
            $encounter = new ClinicalEncounter;
            $encounter->fill($attributes);
            $encounter->patient_id = $patient->getKey();
            // A visit always has a time; default it rather than trusting the
            // caller to always supply one.
            $encounter->encounter_at ??= now();
            $encounter->status = ClinicalEncounter::STATUS_DRAFT;
            $encounter->revision = 1;
            $encounter->created_by = $actor?->getKey();
            $encounter->save();

            $this->audit->record(
                $patient->getKey(),
                'clinical_encounter',
                $encounter->getKey(),
                ClinicalAuditLog::ACTION_ENCOUNTER_OPENED,
                $actor,
                [],
            );

            return $encounter;
        });
    }

    /**
     * Edit a DRAFT encounter. A final or amended encounter is refused — use
     * {@see self::amend()}.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateDraft(ClinicalEncounter $encounter, array $attributes, ?User $actor = null): ClinicalEncounter
    {
        if (! $encounter->isEditable()) {
            throw ClinicalRecordException::notEditable();
        }

        return $this->tenantTransaction(function () use ($encounter, $attributes, $actor): ClinicalEncounter {
            $encounter->fill($this->onlyAllowed($attributes, array_merge(['encounter_at', 'timezone', 'type'], self::AMENDABLE_FIELDS)))->save();

            $this->audit->record(
                $encounter->patient_id,
                'clinical_encounter',
                $encounter->getKey(),
                ClinicalAuditLog::ACTION_ENCOUNTER_UPDATED,
                $actor,
                [],
            );

            return $encounter;
        });
    }

    /**
     * Sign a DRAFT encounter off, freezing its content.
     */
    public function finalize(ClinicalEncounter $encounter, ?User $actor = null): ClinicalEncounter
    {
        if (! $encounter->isDraft()) {
            throw ClinicalRecordException::alreadyFinal();
        }

        return $this->tenantTransaction(function () use ($encounter, $actor): ClinicalEncounter {
            $encounter->forceFill([
                'status' => ClinicalEncounter::STATUS_FINAL,
                'finalized_by' => $actor?->getKey(),
                'finalized_at' => now(),
            ])->save();

            $this->audit->record(
                $encounter->patient_id,
                'clinical_encounter',
                $encounter->getKey(),
                ClinicalAuditLog::ACTION_ENCOUNTER_FINALIZED,
                $actor,
                [],
            );

            return $encounter;
        });
    }

    /**
     * Correct a FINAL / amended encounter. The reason is mandatory and the
     * previous values are retained next to the new ones.
     *
     * @param  array<string, mixed>  $changes
     */
    public function amend(ClinicalEncounter $encounter, array $changes, string $reason, ?User $actor = null): ClinicalEncounter
    {
        if ($encounter->isDraft()) {
            throw ClinicalRecordException::amendmentRequiresFinal();
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ClinicalRecordException::amendmentRequiresReason();
        }

        $changes = $this->onlyAllowed($changes, self::AMENDABLE_FIELDS);

        if ($changes === []) {
            throw ClinicalRecordException::amendmentRequiresReason();
        }

        return $this->tenantTransaction(function () use ($encounter, $changes, $reason, $actor): ClinicalEncounter {
            $previous = [];
            $next = [];

            foreach ($changes as $field => $value) {
                $previous[$field] = $encounter->getOriginal($field);
                $next[$field] = $value;
            }

            $revision = (int) $encounter->revision + 1;

            ClinicalEncounterAmendment::query()->create([
                'encounter_id' => $encounter->getKey(),
                'amended_by' => $actor?->getKey(),
                'reason' => $reason,
                'changes' => ['previous' => $previous, 'new' => $next],
                'revision' => $revision,
                'occurred_at' => now(),
            ]);

            $encounter->forceFill($changes + [
                'status' => ClinicalEncounter::STATUS_AMENDED,
                'amended_by' => $actor?->getKey(),
                'amended_at' => now(),
                'revision' => $revision,
            ])->save();

            $this->audit->record(
                $encounter->patient_id,
                'clinical_encounter',
                $encounter->getKey(),
                ClinicalAuditLog::ACTION_ENCOUNTER_AMENDED,
                $actor,
                ['reason' => $reason, 'revision' => $revision],
            );

            return $encounter;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private function onlyAllowed(array $attributes, array $allowed): array
    {
        return array_intersect_key($attributes, array_flip($allowed));
    }
}
