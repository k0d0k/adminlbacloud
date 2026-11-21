<?php

namespace Modules\Patients\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Patients\Models\ClinicalEncounter;
use Modules\Patients\Models\ClinicalGlucoseReading;
use Modules\Patients\Models\ClinicalLabResult;
use Modules\Patients\Models\ClinicalVital;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientAllergy;
use Modules\Patients\Models\PatientCondition;
use Modules\Patients\Models\PatientHealthHistory;
use Modules\Patients\Models\PatientMedication;

/**
 * Assembles the longitudinal clinical read model for one patient (Phase 2–5).
 *
 * Read-only: it never writes and never changes the record. It exists so the
 * patient detail page and an encounter share ONE consistent way of deciding
 * what "the patient's current health picture" means — active conditions,
 * allergies and medications, the encounter history, and the trend series.
 *
 * Trend series are kept SEPARATE per glucose kind, because a fasting value, a
 * random value and an HbA1c are different measurements and plotting them as one
 * line would be clinically misleading.
 */
final class ClinicalSummary
{
    /**
     * Alerts a clinician must see before treating the patient: active
     * allergies, active conditions and current medications.
     *
     * @return array{allergies: Collection<int, PatientAllergy>, conditions: Collection<int, PatientCondition>, medications: Collection<int, PatientMedication>}
     */
    public function alerts(Patient $patient): array
    {
        return [
            'allergies' => PatientAllergy::query()
                ->where('patient_id', $patient->getKey())
                ->where('is_active', true)
                ->orderByDesc('created_at')
                ->get(),
            'conditions' => PatientCondition::query()
                ->where('patient_id', $patient->getKey())
                ->where('status', PatientCondition::STATUS_ACTIVE)
                ->orderByDesc('created_at')
                ->get(),
            'medications' => PatientMedication::query()
                ->where('patient_id', $patient->getKey())
                ->where('status', PatientMedication::STATUS_ACTIVE)
                ->orderByDesc('created_at')
                ->get(),
        ];
    }

    /**
     * The full read model for the patient detail page.
     *
     * @return array<string, mixed>
     */
    public function forPatient(Patient $patient): array
    {
        $alerts = $this->alerts($patient);

        $vitals = ClinicalVital::query()
            ->where('patient_id', $patient->getKey())
            ->orderBy('measured_at')
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        return [
            'allergies' => PatientAllergy::query()
                ->where('patient_id', $patient->getKey())
                ->orderByDesc('is_active')
                ->orderByDesc('created_at')
                ->get(),
            'conditions' => PatientCondition::query()
                ->where('patient_id', $patient->getKey())
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderByDesc('created_at')
                ->get(),
            'medications' => PatientMedication::query()
                ->where('patient_id', $patient->getKey())
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderByDesc('created_at')
                ->get(),
            'histories' => PatientHealthHistory::query()
                ->where('patient_id', $patient->getKey())
                ->orderByDesc('occurred_on')
                ->orderByDesc('created_at')
                ->get(),
            'encounters' => ClinicalEncounter::query()
                ->where('patient_id', $patient->getKey())
                ->withCount(['vitals', 'glucoseReadings', 'labResults'])
                ->orderByDesc('encounter_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
            'latestVital' => $vitals->last(),
            'vitalSeries' => $this->vitalSeries($vitals),
            'glucoseSeries' => $this->glucoseSeries($patient),
            'labs' => ClinicalLabResult::query()
                ->where('patient_id', $patient->getKey())
                ->orderByDesc('resulted_at')
                ->orderByDesc('created_at')
                ->limit(40)
                ->get(),
            'alerts' => $alerts,
        ];
    }

    /**
     * Blood-pressure and weight trends, as time-ordered points.
     *
     * @param  Collection<int, ClinicalVital>  $vitals
     * @return array{bloodPressure: list<array{at: Carbon, systolic: int, diastolic: int}>, weight: list<array{at: Carbon, value: float}>}
     */
    private function vitalSeries(Collection $vitals): array
    {
        $bloodPressure = [];
        $weight = [];

        foreach ($vitals as $vital) {
            $at = $vital->measured_at ?? $vital->created_at;

            if ($vital->hasBloodPressure()) {
                $bloodPressure[] = [
                    'at' => $at,
                    'systolic' => (int) $vital->systolic,
                    'diastolic' => (int) $vital->diastolic,
                ];
            }

            if ($vital->weight_kg !== null) {
                $weight[] = ['at' => $at, 'value' => (float) $vital->weight_kg];
            }
        }

        return ['bloodPressure' => $bloodPressure, 'weight' => $weight];
    }

    /**
     * Glucose readings grouped BY KIND, so fasting, random, post-meal and HbA1c
     * never share one misleading series.
     *
     * @return array<string, array{unit: string, points: list<array{at: Carbon, value: float}>}>
     */
    private function glucoseSeries(Patient $patient): array
    {
        $readings = ClinicalGlucoseReading::query()
            ->where('patient_id', $patient->getKey())
            ->where('status', ClinicalGlucoseReading::STATUS_MEASURED)
            ->orderBy('measured_at')
            ->orderBy('created_at')
            ->limit(300)
            ->get();

        $series = [];

        foreach (ClinicalGlucoseReading::KINDS as $kind) {
            $series[$kind] = [
                'unit' => $kind === ClinicalGlucoseReading::KIND_HBA1C
                    ? ClinicalGlucoseReading::HBA1C_UNIT
                    : ClinicalGlucoseReading::CANONICAL_GLUCOSE_UNIT,
                'points' => [],
            ];
        }

        foreach ($readings as $reading) {
            $at = $reading->measured_at ?? $reading->created_at;

            $series[$reading->kind]['points'][] = [
                'at' => $at,
                'value' => (float) ($reading->value_normalized ?? $reading->value),
            ];
        }

        return $series;
    }
}
