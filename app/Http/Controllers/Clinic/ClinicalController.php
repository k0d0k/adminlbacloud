<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Clinic\Concerns\AuthorizesClinicalRecords;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Models\Examination;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\Patients\Models\ClinicalEncounter;
use Modules\Patients\Models\ClinicalGlucoseReading;
use Modules\Patients\Models\ClinicalVital;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientAllergy;
use Modules\Patients\Models\PatientCondition;
use Modules\Patients\Models\PatientHealthHistory;
use Modules\Patients\Models\PatientMedication;
use Modules\Patients\Services\ClinicalEncounterRegistrar;
use Modules\Patients\Services\ClinicalMeasurementRecorder;
use Modules\Patients\Services\ClinicalProfileRegistrar;
use Modules\Patients\Services\ClinicalSummary;
use Modules\Patients\Support\GlucoseUnitConverter;

/**
 * Rekam Medis Elektronik (RME) — clinical profile, encounters and measurements
 * (Phases 2–4).
 *
 * Every clinical surface is authorized server-side: the Patient module must be
 * active in the Clinic panel AND the acting identity must hold the canonical
 * clinical permission for the verified tenant/branch context. Nothing trusts a
 * client-supplied tenant, branch or patient id — the patient, encounter and
 * profile entries are resolved inside the verified tenant database, and a
 * branch-scoped write is refused when the actor cannot operate in that branch.
 *
 * Reads are assigned to `clinical.record.read`; writes to
 * `clinical.record.manage`; signing off or amending a note to
 * `clinical.encounter.finalize`.
 */
final class ClinicalController extends Controller
{
    use AuthorizesClinicalRecords;

    /**
     * Profile-entry type => [model class, audit subject slug].
     *
     * @var array<string, array{0: class-string<TenantModel>, 1: string}>
     */
    private const ENTRY_TYPES = [
        'conditions' => [PatientCondition::class, ClinicalProfileRegistrar::SUBJECT_CONDITION],
        'allergies' => [PatientAllergy::class, ClinicalProfileRegistrar::SUBJECT_ALLERGY],
        'medications' => [PatientMedication::class, ClinicalProfileRegistrar::SUBJECT_MEDICATION],
        'histories' => [PatientHealthHistory::class, ClinicalProfileRegistrar::SUBJECT_HISTORY],
    ];

    /** Guard against an implicit `{entry}` meriting a wider route model. */
    private const ULID = '[0-9A-Za-z]{26}';

    public function __construct(
        private readonly ClinicalProfileRegistrar $profile,
        private readonly ClinicalEncounterRegistrar $encounters,
        private readonly ClinicalMeasurementRecorder $measurements,
        private readonly ClinicalSummary $summary,
    ) {}

    // ---------------------------------------------------- Profile entries

    public function storeEntry(Request $request, string $patient, string $type): RedirectResponse
    {
        $this->authorizeClinical(PermissionKey::CLINICAL_RECORD_MANAGE);
        $patient = $this->resolvePatient($patient);
        $this->entryType($type);

        $attributes = $this->validatedEntry($request, $type);

        match ($type) {
            'conditions' => $this->profile->addCondition($patient, $attributes, $request->user()),
            'allergies' => $this->profile->addAllergy($patient, $attributes, $request->user()),
            'medications' => $this->profile->addMedication($patient, $attributes, $request->user()),
            default => $this->profile->addHistory($patient, $attributes, $request->user()),
        };

        return $this->backToProfile($patient, 'lba.clinical.entry_saved');
    }

    public function updateEntry(Request $request, string $patient, string $type, string $entry): RedirectResponse
    {
        $this->authorizeClinical(PermissionKey::CLINICAL_RECORD_MANAGE);
        $patient = $this->resolvePatient($patient);
        $record = $this->resolveEntry($patient, $type, $entry);

        $attributes = $this->validatedEntry($request, $type);

        match ($type) {
            'conditions' => $this->profile->updateCondition($record, $attributes, $request->user()),
            'allergies' => $this->profile->updateAllergy($record, $attributes, $request->user()),
            'medications' => $this->profile->updateMedication($record, $attributes, $request->user()),
            default => $this->profile->updateHistory($record, $attributes, $request->user()),
        };

        return $this->backToProfile($patient, 'lba.clinical.entry_updated');
    }

    public function verifyEntry(Request $request, string $patient, string $type, string $entry): RedirectResponse
    {
        $this->authorizeClinical(PermissionKey::CLINICAL_RECORD_MANAGE);
        $patient = $this->resolvePatient($patient);
        $record = $this->resolveEntry($patient, $type, $entry);

        $this->profile->verify($record, $request->user());

        return $this->backToProfile($patient, 'lba.clinical.entry_verified');
    }

    public function setAllergyStatus(Request $request, string $patient): RedirectResponse
    {
        $this->authorizeClinical(PermissionKey::CLINICAL_RECORD_MANAGE);
        $patient = $this->resolvePatient($patient);

        $validated = $request->validate([
            'allergy_status' => ['required', Rule::in(Patient::ALLERGY_STATUSES)],
        ]);

        $this->profile->setAllergyStatus($patient, $validated['allergy_status'], $request->user());

        return $this->backToProfile($patient, 'lba.clinical.allergy_status_saved');
    }

    // ------------------------------------------------------- Encounters

    public function storeEncounter(Request $request, string $patient, ManagesBranchContext $branches): RedirectResponse
    {
        $this->authorizeClinical(PermissionKey::CLINICAL_RECORD_MANAGE);
        $patient = $this->resolvePatient($patient);

        $attributes = $this->validatedEncounter($request);
        $attributes['branch_id'] = $this->currentBranchId($request->user());
        $attributes['practitioner_id'] = $attributes['practitioner_id'] ?? null;

        $encounter = $this->encounters->open($patient, $attributes, $request->user());

        return to_route('clinic.tailadmin.patients.encounters.show', [$patient, $encounter])
            ->with('success', __('lba.clinical.encounter_opened'));
    }

    public function showEncounter(Request $request, string $patient, string $encounter): View
    {
        $this->authorizeClinical(PermissionKey::CLINICAL_RECORD_READ);
        $patient = $this->resolvePatient($patient);
        $encounter = $this->resolveEncounter($patient, $encounter);

        $encounter->load(['vitals', 'glucoseReadings', 'labResults', 'amendments']);

        return view('clinic.tailadmin.encounter-show', [
            'patient' => $patient,
            'encounter' => $encounter,
            'alerts' => $this->summary->alerts($patient),
            'canManage' => $this->clinicalAllows(PermissionKey::CLINICAL_RECORD_MANAGE),
            'canFinalize' => $this->clinicalAllows(PermissionKey::CLINICAL_ENCOUNTER_FINALIZE),
            'glucoseUnits' => GlucoseUnitConverter::SUPPORTED_UNITS,
            'lbaExaminations' => $this->lbaExaminations($encounter),
            'lbaAvailable' => class_exists(Examination::class),
        ]);
    }

    public function updateEncounter(Request $request, string $patient, string $encounter): RedirectResponse
    {
        $this->authorizeClinical(PermissionKey::CLINICAL_RECORD_MANAGE);
        $patient = $this->resolvePatient($patient);
        $encounter = $this->resolveEncounter($patient, $encounter);

        $this->encounters->updateDraft($encounter, $this->validatedEncounter($request), $request->user());

        return $this->backToEncounter($patient, $encounter, 'lba.clinical.encounter_saved');
    }

    public function finalizeEncounter(Request $request, string $patient, string $encounter): RedirectResponse
    {
        $this->authorizeClinical(PermissionKey::CLINICAL_ENCOUNTER_FINALIZE);
        $patient = $this->resolvePatient($patient);
        $encounter = $this->resolveEncounter($patient, $encounter);

        $this->encounters->finalize($encounter, $request->user());

        return $this->backToEncounter($patient, $encounter, 'lba.clinical.encounter_finalized');
    }

    public function amendEncounter(Request $request, string $patient, string $encounter): RedirectResponse
    {
        $this->authorizeClinical(PermissionKey::CLINICAL_ENCOUNTER_FINALIZE);
        $patient = $this->resolvePatient($patient);
        $encounter = $this->resolveEncounter($patient, $encounter);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'subjective' => ['nullable', 'string', 'max:5000'],
            'objective' => ['nullable', 'string', 'max:5000'],
            'assessment' => ['nullable', 'string', 'max:5000'],
            'plan' => ['nullable', 'string', 'max:5000'],
        ]);

        $reason = $validated['reason'];
        unset($validated['reason']);

        $this->encounters->amend($encounter, array_filter($validated, static fn ($value) => $value !== null), $reason, $request->user());

        return $this->backToEncounter($patient, $encounter, 'lba.clinical.encounter_amended');
    }

    // ----------------------------------------------------- Measurements

    public function storeMeasurement(Request $request, string $patient, string $encounter, string $kind): RedirectResponse
    {
        $this->authorizeClinical(PermissionKey::CLINICAL_RECORD_MANAGE);
        $patient = $this->resolvePatient($patient);
        $encounter = $this->resolveEncounter($patient, $encounter);

        // Measurements may still be attached to a frozen encounter: adding a
        // reading does not rewrite the signed note it belongs to.
        match ($kind) {
            'vitals' => $this->measurements->recordVitals($encounter, $this->validatedVitals($request), $request->user()),
            'glucose' => $this->measurements->recordGlucose($encounter, $this->validatedGlucose($request), $request->user()),
            'labs' => $this->measurements->recordLabResult($patient, $encounter, $this->validatedLab($request), $request->user()),
            default => abort(404),
        };

        return $this->backToEncounter($patient, $encounter, 'lba.clinical.measurement_saved');
    }

    // --------------------------------------------------------- LBA handoff

    /**
     * Open an LBA examination FROM this clinical encounter (Phase 4).
     *
     * This never duplicates the patient or invents a second examination
     * workflow: it records the provenance link only, and is idempotent on the
     * encounter, so a second press returns the examination already linked.
     */
    public function startLbaExamination(Request $request, string $patient, string $encounter): RedirectResponse
    {
        $this->authorizeClinical(PermissionKey::CLINICAL_RECORD_MANAGE);
        $patient = $this->resolvePatient($patient);
        $encounter = $this->resolveEncounter($patient, $encounter);

        abort_unless(class_exists(Examination::class), 404);

        $validated = $request->validate([
            'record_consent' => ['sometimes', 'boolean'],
        ]);

        try {
            $examination = app(ExaminationRegistrar::class)
                ->intakeFromClinicalEncounter($encounter, $request->user(), $validated);
        } catch (ExaminationException $exception) {
            throw ValidationException::withMessages(['lba' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.examinations.show', $examination)
            ->with('success', __('lba.clinical.lba.opened'));
    }

    // -------------------------------------------------------- Validation

    /**
     * @return array<string, mixed>
     */
    private function validatedEntry(Request $request, string $type): array
    {
        return match ($type) {
            'conditions' => $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'icd10_code' => ['nullable', 'string', 'max:16'],
                'onset_date' => ['nullable', 'date'],
                'onset_note' => ['nullable', 'string', 'max:255'],
                'status' => ['nullable', Rule::in(PatientCondition::STATUSES)],
                'source' => ['nullable', Rule::in(PatientCondition::SOURCES)],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]),
            'allergies' => $request->validate([
                'substance' => ['required', 'string', 'max:255'],
                'category' => ['nullable', Rule::in(PatientAllergy::CATEGORIES)],
                'reaction' => ['nullable', 'string', 'max:255'],
                'severity' => ['nullable', Rule::in(PatientAllergy::SEVERITIES)],
                'occurred_on' => ['nullable', 'date'],
                'is_active' => ['nullable', 'boolean'],
                'source' => ['nullable', Rule::in(PatientCondition::SOURCES)],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]),
            'medications' => $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'strength' => ['nullable', 'string', 'max:120'],
                'dose' => ['nullable', 'string', 'max:120'],
                'route' => ['nullable', 'string', 'max:120'],
                'frequency' => ['nullable', 'string', 'max:120'],
                'indication' => ['nullable', 'string', 'max:255'],
                'started_on' => ['nullable', 'date'],
                'ended_on' => ['nullable', 'date'],
                'status' => ['nullable', Rule::in(PatientMedication::STATUSES)],
                'source' => ['nullable', Rule::in(PatientCondition::SOURCES)],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]),
            default => $request->validate([
                'category' => ['required', Rule::in(PatientHealthHistory::CATEGORIES)],
                'title' => ['required', 'string', 'max:255'],
                'detail' => ['nullable', 'string', 'max:2000'],
                'occurred_on' => ['nullable', 'date'],
                'source' => ['nullable', Rule::in(PatientCondition::SOURCES)],
            ]),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedEncounter(Request $request): array
    {
        return $request->validate([
            'encounter_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'type' => ['nullable', Rule::in(ClinicalEncounter::TYPES)],
            'practitioner_id' => ['nullable', 'string', 'max:26'],
            'chief_complaint' => ['nullable', 'string', 'max:2000'],
            'history_of_present_illness' => ['nullable', 'string', 'max:5000'],
            'physical_exam' => ['nullable', 'string', 'max:5000'],
            'supporting_findings' => ['nullable', 'string', 'max:5000'],
            'subjective' => ['nullable', 'string', 'max:5000'],
            'objective' => ['nullable', 'string', 'max:5000'],
            'assessment' => ['nullable', 'string', 'max:5000'],
            'plan' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedVitals(Request $request): array
    {
        return $request->validate([
            'measured_at' => ['nullable', 'date'],
            'position' => ['nullable', Rule::in(ClinicalVital::POSITIONS)],
            'arm' => ['nullable', Rule::in(ClinicalVital::ARMS)],
            'cuff_size' => ['nullable', 'string', 'max:64'],
            'device' => ['nullable', 'string', 'max:120'],
            'systolic' => ['nullable', 'integer', 'min:1', 'max:400'],
            'diastolic' => ['nullable', 'integer', 'min:1', 'max:300'],
            'pulse' => ['nullable', 'integer', 'min:1', 'max:400'],
            'respiratory_rate' => ['nullable', 'integer', 'min:1', 'max:120'],
            'temperature_c' => ['nullable', 'numeric', 'min:25', 'max:45'],
            'oxygen_saturation' => ['nullable', 'integer', 'min:1', 'max:100'],
            'weight_kg' => ['nullable', 'numeric', 'min:0.1', 'max:500'],
            'height_cm' => ['nullable', 'numeric', 'min:10', 'max:300'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:120'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedGlucose(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::in(ClinicalGlucoseReading::KINDS)],
            'value' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'unit' => ['nullable', 'string', 'max:16'],
            'specimen' => ['nullable', 'string', 'max:64'],
            'method' => ['nullable', 'string', 'max:120'],
            'measured_at' => ['nullable', 'date'],
            'last_meal_at' => ['nullable', 'date'],
            'source_name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedLab(Request $request): array
    {
        return $request->validate([
            'test_name' => ['required', 'string', 'max:255'],
            'result_value' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:64'],
            'reference_range' => ['nullable', 'string', 'max:255'],
            'collected_at' => ['nullable', 'date'],
            'resulted_at' => ['nullable', 'date'],
            'source_name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    // ------------------------------------------------------ Resolution

    private function resolvePatient(string $patient): Patient
    {
        return Patient::query()->whereKey($patient)->firstOrFail();
    }

    private function resolveEncounter(Patient $patient, string $encounter): ClinicalEncounter
    {
        $record = ClinicalEncounter::query()
            ->whereKey($encounter)
            ->where('patient_id', $patient->getKey())
            ->firstOrFail();

        $this->assertBranchAccessible($record->branch_id);

        return $record;
    }

    private function resolveEntry(Patient $patient, string $type, string $entry): TenantModel
    {
        [$model] = $this->entryType($type);

        return $model::query()
            ->whereKey($entry)
            ->where('patient_id', $patient->getKey())
            ->firstOrFail();
    }

    /**
     * @return array{0: class-string<TenantModel>, 1: string}
     */
    private function entryType(string $type): array
    {
        abort_unless(isset(self::ENTRY_TYPES[$type]), 404);

        return self::ENTRY_TYPES[$type];
    }

    /**
     * The LBA examinations already linked to this encounter (Phase 4). The LBA
     * module is an optional dependency, so its absence degrades to an empty
     * list rather than a fatal error.
     *
     * @return iterable<int, Examination>
     */
    private function lbaExaminations(ClinicalEncounter $encounter): iterable
    {
        if (! class_exists(Examination::class)) {
            return collect();
        }

        return Examination::query()
            ->where('clinical_encounter_id', $encounter->getKey())
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get();
    }

    // ---------------------------------------------------- Authorization

    private function currentBranchId(?User $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        return app(ManagesBranchContext::class)->currentBranchId($user);
    }

    // ------------------------------------------------------- Redirects

    private function backToProfile(Patient $patient, string $message): RedirectResponse
    {
        return to_route('clinic.tailadmin.patients.show', ['patient' => $patient, 'tab' => 'history'])
            ->with('success', __($message));
    }

    private function backToEncounter(Patient $patient, ClinicalEncounter $encounter, string $message): RedirectResponse
    {
        return to_route('clinic.tailadmin.patients.encounters.show', [$patient, $encounter])
            ->with('success', __($message));
    }
}
