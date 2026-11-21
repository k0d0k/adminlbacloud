<?php

namespace Modules\Patients\Services;

use App\Models\User;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Modules\Patients\Exceptions\ClinicalRecordException;
use Modules\Patients\Models\ClinicalAuditLog;
use Modules\Patients\Models\ClinicalEncounter;
use Modules\Patients\Models\ClinicalGlucoseReading;
use Modules\Patients\Models\ClinicalLabResult;
use Modules\Patients\Models\ClinicalVital;
use Modules\Patients\Models\Patient;
use Modules\Patients\Support\BodyMassIndex;
use Modules\Patients\Support\GlucoseUnitConverter;

/**
 * Records the objective measurements of an encounter — vital signs, blood
 * glucose / HbA1c and supporting laboratory results (Phase 3A–3C).
 *
 * Two invariants are enforced here rather than trusted to the caller:
 *
 *  - a value that was never measured is stored as NULL with an explicit
 *    `not_measured` / `not_performed` status, never as 0, so a blank reading can
 *    never be mistaken for a real zero;
 *  - a blood-glucose value is kept VERBATIM (`value` + `unit`) and a derived
 *    canonical value is stored alongside it, so unit conversion never rewrites
 *    the original record.
 */
final class ClinicalMeasurementRecorder
{
    use RunsVerifiedTenantTransactions;

    /** The numeric fields that make up a measured vital-sign set. */
    public const VITAL_FIELDS = [
        'systolic',
        'diastolic',
        'pulse',
        'respiratory_rate',
        'temperature_c',
        'oxygen_saturation',
        'weight_kg',
        'height_cm',
    ];

    public const VITAL_CONTEXT_FIELDS = [
        'measured_at',
        'position',
        'arm',
        'cuff_size',
        'device',
        'notes',
        'source',
    ];

    public function __construct(private readonly ClinicalAuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordVitals(ClinicalEncounter $encounter, array $attributes, ?User $actor = null): ClinicalVital
    {
        return $this->tenantTransaction(function () use ($encounter, $attributes, $actor): ClinicalVital {
            $vital = new ClinicalVital;
            $vital->fill(array_intersect_key($attributes, array_flip([...self::VITAL_FIELDS, ...self::VITAL_CONTEXT_FIELDS])));
            $vital->encounter_id = $encounter->getKey();
            $vital->patient_id = $encounter->patient_id;
            $vital->status = $this->hasAnyMeasurement($attributes)
                ? ClinicalVital::STATUS_MEASURED
                : ClinicalVital::STATUS_NOT_MEASURED;
            $vital->measured_by = $actor?->getKey();
            $vital->recorded_by = $actor?->getKey();
            $vital->bmi = BodyMassIndex::calculate(
                $vital->weight_kg === null ? null : (float) $vital->weight_kg,
                $vital->height_cm === null ? null : (float) $vital->height_cm,
            );
            $vital->save();

            $this->audit->record(
                $encounter->patient_id,
                'clinical_vital',
                $vital->getKey(),
                ClinicalAuditLog::ACTION_VITALS_RECORDED,
                $actor,
                [],
            );

            return $vital;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordGlucose(ClinicalEncounter $encounter, array $attributes, ?User $actor = null): ClinicalGlucoseReading
    {
        $kind = (string) ($attributes['kind'] ?? '');

        if (! in_array($kind, ClinicalGlucoseReading::KINDS, true)) {
            throw ClinicalRecordException::unknownKind($kind);
        }

        return $this->tenantTransaction(function () use ($encounter, $attributes, $kind, $actor): ClinicalGlucoseReading {
            $reading = new ClinicalGlucoseReading;
            $reading->fill(array_intersect_key($attributes, array_flip([
                'kind', 'specimen', 'method', 'measured_at', 'last_meal_at', 'source_name', 'notes',
            ])));
            $reading->encounter_id = $encounter->getKey();
            $reading->patient_id = $encounter->patient_id;
            $reading->recorded_by = $actor?->getKey();

            $raw = $attributes['value'] ?? null;
            $isMeasured = $raw !== null && $raw !== '';

            if (! $isMeasured) {
                // Never coerce a missing measurement into 0.
                $reading->status = ClinicalGlucoseReading::STATUS_NOT_PERFORMED;
                $reading->value = null;
                $reading->unit = null;
                $reading->value_normalized = null;
                $reading->normalized_unit = null;
            } else {
                $value = (float) $raw;
                $reading->status = ClinicalGlucoseReading::STATUS_MEASURED;
                $reading->value = $value;

                if ($kind === ClinicalGlucoseReading::KIND_HBA1C) {
                    // HbA1c is a percentage and is never unit-converted.
                    $reading->unit = ClinicalGlucoseReading::HBA1C_UNIT;
                    $reading->value_normalized = $value;
                    $reading->normalized_unit = ClinicalGlucoseReading::HBA1C_UNIT;
                } else {
                    $unit = (string) ($attributes['unit'] ?? GlucoseUnitConverter::UNIT_MGDL);

                    if (! GlucoseUnitConverter::supports($unit)) {
                        throw ClinicalRecordException::unsupportedGlucoseUnit($unit);
                    }

                    $reading->unit = $unit;
                    $canonical = GlucoseUnitConverter::toCanonical($value, $unit);
                    $reading->value_normalized = $canonical['value'];
                    $reading->normalized_unit = $canonical['unit'];
                }
            }

            $reading->save();

            $this->audit->record(
                $encounter->patient_id,
                'clinical_glucose_reading',
                $reading->getKey(),
                ClinicalAuditLog::ACTION_GLUCOSE_RECORDED,
                $actor,
                ['kind' => $kind],
            );

            return $reading;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordLabResult(Patient $patient, ?ClinicalEncounter $encounter, array $attributes, ?User $actor = null): ClinicalLabResult
    {
        return $this->tenantTransaction(function () use ($patient, $encounter, $attributes, $actor): ClinicalLabResult {
            $result = new ClinicalLabResult;
            $result->fill(array_intersect_key($attributes, array_flip([
                'test_name', 'result_value', 'unit', 'reference_range',
                'collected_at', 'resulted_at', 'source_name', 'notes',
            ])));
            $result->patient_id = $patient->getKey();
            $result->encounter_id = $encounter?->getKey();
            $result->recorded_by = $actor?->getKey();
            $result->save();

            $this->audit->record(
                $patient->getKey(),
                'clinical_lab_result',
                $result->getKey(),
                ClinicalAuditLog::ACTION_LAB_RESULT_RECORDED,
                $actor,
                [],
            );

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function hasAnyMeasurement(array $attributes): bool
    {
        foreach (self::VITAL_FIELDS as $field) {
            if (($attributes[$field] ?? null) !== null && ($attributes[$field] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }
}
