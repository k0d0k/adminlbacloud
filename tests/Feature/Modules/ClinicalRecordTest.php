<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\LBA\Models\Examination;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\Patients\Exceptions\ClinicalRecordException;
use Modules\Patients\Models\ClinicalEncounter;
use Modules\Patients\Models\ClinicalEncounterAmendment;
use Modules\Patients\Models\ClinicalGlucoseReading;
use Modules\Patients\Models\ClinicalLabResult;
use Modules\Patients\Models\ClinicalVital;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientAllergy;
use Modules\Patients\Models\PatientCondition;
use Modules\Patients\Services\ClinicalEncounterRegistrar;
use Modules\Patients\Services\ClinicalMeasurementRecorder;
use Modules\Patients\Services\ClinicalProfileRegistrar;
use Modules\Patients\Services\ClinicalSummary;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 7 — Profil Pasien & Rekam Medis Elektronik acceptance.
 *
 * Only SYNTHETIC identities, patients and clinical values are used; no real
 * patient data ever enters the suite (DECISIONS.md PRV-01). Every tenant lives
 * in its own isolated SQLite database, so the isolation assertions exercise a
 * genuine separate database rather than a logical filter.
 */
class ClinicalRecordTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();
        parent::tearDown();
    }

    /**
     * A ready tenant with both the Patients and LBA modules active.
     *
     * @return array{0: Tenant, 1: string} tenant + its single branch id
     */
    private function makeTenant(): array
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        foreach (['patients', 'lba'] as $module) {
            // The registry is central and unique per module key, so a second
            // tenant in the same test must reuse an already-registered module.
            if (! RegisteredModule::query()->where('module_key', $module)->exists()) {
                RegisteredModule::factory()->forKey($module)->enabled()->create();
            }

            ModuleInstallation::factory()->forModule($module)->forScope(ModuleScope::TENANT, $tenant->id)->enabled()->create();
            ModuleEntitlement::factory()->forModule($module)->forScope(ModuleScope::TENANT, $tenant->id)->granted()->create();
        }

        $branch = $this->createBranch($tenant);

        return [$tenant, $branch->id];
    }

    // ------------------------------------------------------ Phase 2 profile

    public function test_health_profile_entries_start_unverified_and_are_promoted_by_review(): void
    {
        [$tenant, $branchId] = $this->makeTenant();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);

        $this->tenantDatabases()->configure($tenant->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'Profil Pasien', 'status' => Patient::STATUS_ACTIVE], $owner);

        $profile = app(ClinicalProfileRegistrar::class);

        // A patient-reported hypertension is recorded, but NEVER promoted to a
        // verified diagnosis by the act of typing it.
        $condition = $profile->addCondition($patient, [
            'name' => 'Hipertensi',
            'status' => PatientCondition::STATUS_ACTIVE,
            'source' => PatientCondition::SOURCE_PATIENT,
        ], $owner);

        $this->assertFalse($condition->isVerified());
        $this->assertTrue($condition->isPatientReported());

        // A clinician review promotes it and stamps who reviewed it.
        $profile->verify($condition, $owner);
        $this->assertTrue($condition->refresh()->isVerified());
        $this->assertSame((string) $owner->id, $condition->verified_by);

        // Allergies, medications and supporting history are all multi-entry.
        $allergy = $profile->addAllergy($patient, ['substance' => 'Penisilin', 'category' => PatientAllergy::CATEGORY_MEDICATION], $owner);
        $profile->addMedication($patient, ['name' => 'Metformin', 'status' => 'active'], $owner);
        $profile->addHistory($patient, ['category' => 'surgery', 'title' => 'Appendektomi'], $owner);

        $this->assertNotNull($allergy->getKey());

        // Adding profile history does not touch any encounter.
        $encounters = ClinicalEncounter::query()->count();
        $profile->addCondition($patient, ['name' => 'Asma', 'source' => PatientCondition::SOURCE_PATIENT], $owner);
        $this->assertSame($encounters, ClinicalEncounter::query()->count());
    }

    public function test_unknown_allergy_status_differs_from_no_known_allergies(): void
    {
        [$tenant] = $this->makeTenant();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);

        $this->tenantDatabases()->configure($tenant->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'Alergi Pasien', 'status' => Patient::STATUS_ACTIVE], $owner);

        // The default is "not yet assessed" — distinct from "no known allergies".
        $this->assertSame(Patient::ALLERGY_STATUS_UNKNOWN, $patient->allergy_status);
        $this->assertNotSame(Patient::ALLERGY_STATUS_NONE_KNOWN, $patient->allergy_status);

        app(ClinicalProfileRegistrar::class)->setAllergyStatus($patient, Patient::ALLERGY_STATUS_NONE_KNOWN, $owner);
        $this->assertSame(Patient::ALLERGY_STATUS_NONE_KNOWN, $patient->refresh()->allergy_status);

        app(ClinicalProfileRegistrar::class)->setAllergyStatus($patient, Patient::ALLERGY_STATUS_HAS_ALLERGIES, $owner);
        $this->assertTrue($patient->refresh()->hasAllergies());
    }

    // ---------------------------------------------------- Phase 3 encounters

    public function test_two_encounters_keep_separate_vitals_and_labs(): void
    {
        [$tenant, $branchId] = $this->makeTenant();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);

        $this->tenantDatabases()->configure($tenant->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'Dua Kunjungan', 'status' => Patient::STATUS_ACTIVE], $owner);

        $encounters = app(ClinicalEncounterRegistrar::class);
        $measurements = app(ClinicalMeasurementRecorder::class);

        $first = $encounters->open($patient, ['branch_id' => $branchId, 'type' => ClinicalEncounter::TYPE_NEW, 'encounter_at' => now()->subMonth()], $owner);
        $second = $encounters->open($patient, ['branch_id' => $branchId, 'type' => ClinicalEncounter::TYPE_FOLLOW_UP, 'encounter_at' => now()], $owner);

        $measurements->recordVitals($first, ['systolic' => 150, 'diastolic' => 95], $owner);
        $measurements->recordLabResult($patient, $first, ['test_name' => 'Hemoglobin', 'result_value' => '13.1', 'unit' => 'g/dL'], $owner);

        $measurements->recordVitals($second, ['systolic' => 120, 'diastolic' => 80], $owner);
        $measurements->recordLabResult($patient, $second, ['test_name' => 'Hemoglobin', 'result_value' => '14.2', 'unit' => 'g/dL'], $owner);
        $measurements->recordLabResult($patient, $second, ['test_name' => 'Kolesterol', 'result_value' => '180', 'unit' => 'mg/dL'], $owner);

        // Each visit is its own row and its own measurements — nothing overwrites.
        $this->assertSame(1, ClinicalVital::query()->where('encounter_id', $first->id)->count());
        $this->assertSame(1, ClinicalVital::query()->where('encounter_id', $second->id)->count());
        $this->assertSame(1, ClinicalLabResult::query()->where('encounter_id', $first->id)->count());
        $this->assertSame(2, ClinicalLabResult::query()->where('encounter_id', $second->id)->count());
        $this->assertSame(150, ClinicalVital::query()->where('encounter_id', $first->id)->sole()->systolic);
        $this->assertSame(120, ClinicalVital::query()->where('encounter_id', $second->id)->sole()->systolic);
    }

    public function test_blood_pressure_can_be_measured_more_than_once_in_one_encounter(): void
    {
        [$tenant, $branchId] = $this->makeTenant();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);

        $this->tenantDatabases()->configure($tenant->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'Tensi Ulang', 'status' => Patient::STATUS_ACTIVE], $owner);

        $encounter = app(ClinicalEncounterRegistrar::class)->open($patient, ['branch_id' => $branchId, 'type' => ClinicalEncounter::TYPE_NEW], $owner);
        $recorder = app(ClinicalMeasurementRecorder::class);

        $recorder->recordVitals($encounter, ['systolic' => 158, 'diastolic' => 99, 'position' => ClinicalVital::POSITION_SITTING, 'arm' => ClinicalVital::ARM_LEFT], $owner);
        $recorder->recordVitals($encounter, ['systolic' => 142, 'diastolic' => 88, 'position' => ClinicalVital::POSITION_SITTING, 'arm' => ClinicalVital::ARM_RIGHT], $owner);

        $readings = ClinicalVital::query()->where('encounter_id', $encounter->id)->orderBy('created_at')->get();
        $this->assertCount(2, $readings);
        $this->assertSame('158/99', $readings[0]->bloodPressureLabel());
        $this->assertSame(ClinicalVital::ARM_LEFT, $readings[0]->arm);
        $this->assertSame('142/88', $readings[1]->bloodPressureLabel());
        $this->assertSame(ClinicalVital::ARM_RIGHT, $readings[1]->arm);
    }

    public function test_a_missing_measurement_is_never_stored_or_shown_as_zero(): void
    {
        [$tenant, $branchId] = $this->makeTenant();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);

        $this->tenantDatabases()->configure($tenant->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'Glukosa Kosong', 'status' => Patient::STATUS_ACTIVE], $owner);
        $encounter = app(ClinicalEncounterRegistrar::class)->open($patient, ['branch_id' => $branchId, 'type' => ClinicalEncounter::TYPE_NEW], $owner);

        // A blank value means "not performed" — NOT a zero reading.
        $reading = app(ClinicalMeasurementRecorder::class)->recordGlucose($encounter, [
            'kind' => ClinicalGlucoseReading::KIND_FASTING,
            'value' => '',
            'unit' => ClinicalGlucoseReading::CANONICAL_GLUCOSE_UNIT,
        ], $owner);

        $this->assertSame(ClinicalGlucoseReading::STATUS_NOT_PERFORMED, $reading->status);
        $this->assertNull($reading->value);
        $this->assertNull($reading->value_normalized);
        $this->assertFalse($reading->isMeasured());

        // A truly unmeasured vital set is likewise not a zero.
        $vital = app(ClinicalMeasurementRecorder::class)->recordVitals($encounter, ['notes' => 'Pasien menolak'], $owner);
        $this->assertSame(ClinicalVital::STATUS_NOT_MEASURED, $vital->status);
        $this->assertNull($vital->systolic);
        $this->assertNull($vital->bmi);
    }

    public function test_glucose_unit_conversion_keeps_the_original_value_verbatim(): void
    {
        [$tenant, $branchId] = $this->makeTenant();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);

        $this->tenantDatabases()->configure($tenant->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'Konversi Glukosa', 'status' => Patient::STATUS_ACTIVE], $owner);
        $encounter = app(ClinicalEncounterRegistrar::class)->open($patient, ['branch_id' => $branchId, 'type' => ClinicalEncounter::TYPE_NEW], $owner);
        $recorder = app(ClinicalMeasurementRecorder::class);

        $mmol = $recorder->recordGlucose($encounter, [
            'kind' => ClinicalGlucoseReading::KIND_RANDOM,
            'value' => 5.5,
            'unit' => 'mmol/L',
        ], $owner);

        // The operator's value and unit are preserved; the canonical value is
        // derived alongside and never replaces them.
        $this->assertSame('mmol/L', $mmol->unit);
        $this->assertEqualsWithDelta(5.5, (float) $mmol->value, 0.0001);
        $this->assertSame(ClinicalGlucoseReading::CANONICAL_GLUCOSE_UNIT, $mmol->normalized_unit);
        $this->assertEqualsWithDelta(99.1, (float) $mmol->value_normalized, 0.1);

        // HbA1c is a percentage and must never be unit-converted.
        $hba1c = $recorder->recordGlucose($encounter, [
            'kind' => ClinicalGlucoseReading::KIND_HBA1C,
            'value' => 7.2,
            'unit' => 'mg/dL',
        ], $owner);
        $this->assertSame(ClinicalGlucoseReading::HBA1C_UNIT, $hba1c->unit);
        $this->assertEqualsWithDelta(7.2, (float) $hba1c->value_normalized, 0.0001);
    }

    public function test_final_note_is_frozen_and_only_correctable_through_an_audited_amendment(): void
    {
        [$tenant, $branchId] = $this->makeTenant();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);

        $this->tenantDatabases()->configure($tenant->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'SOAP Final', 'status' => Patient::STATUS_ACTIVE], $owner);

        $registrar = app(ClinicalEncounterRegistrar::class);
        $encounter = $registrar->open($patient, ['branch_id' => $branchId, 'type' => ClinicalEncounter::TYPE_NEW, 'assessment' => 'Observasi awal'], $owner);

        $registrar->finalize($encounter, $owner);
        $this->assertTrue($encounter->refresh()->isFinal());
        $encounter->load('amendments');
        $this->assertSame(1, $encounter->revision);

        // A final record refuses a direct edit.
        try {
            $registrar->updateDraft($encounter, ['assessment' => 'Ditimpa diam-diam'], $owner);
            $this->fail('A final encounter must not be editable directly.');
        } catch (ClinicalRecordException) {
            // expected
        }
        $this->assertSame('Observasi awal', $encounter->refresh()->assessment);

        // A correction is an amendment that MUST state a reason.
        try {
            $registrar->amend($encounter, ['assessment' => 'Observasi lanjutan'], '   ', $owner);
            $this->fail('An amendment without a reason must be refused.');
        } catch (ClinicalRecordException) {
            // expected
        }

        $registrar->amend($encounter, ['assessment' => 'Observasi lanjutan'], 'Klarifikasi hasil ulang', $owner);
        $this->assertSame('Observasi lanjutan', $encounter->refresh()->assessment);
        $this->assertTrue($encounter->isAmended());
        $this->assertSame(2, $encounter->revision);

        $amendment = ClinicalEncounterAmendment::query()->where('encounter_id', $encounter->id)->sole();
        $this->assertSame('Klarifikasi hasil ulang', $amendment->reason);
        $this->assertSame('Observasi awal', $amendment->changes['previous']['assessment']);
        $this->assertSame('Observasi lanjutan', $amendment->changes['new']['assessment']);
    }

    public function test_summary_series_keep_time_and_units_correct_per_kind(): void
    {
        [$tenant, $branchId] = $this->makeTenant();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);

        $this->tenantDatabases()->configure($tenant->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'Tren Pasien', 'status' => Patient::STATUS_ACTIVE], $owner);
        $encounter = app(ClinicalEncounterRegistrar::class)->open($patient, ['branch_id' => $branchId, 'type' => ClinicalEncounter::TYPE_NEW], $owner);
        $recorder = app(ClinicalMeasurementRecorder::class);

        $recorder->recordVitals($encounter, ['systolic' => 130, 'diastolic' => 85, 'weight_kg' => 70], $owner);
        $recorder->recordGlucose($encounter, ['kind' => ClinicalGlucoseReading::KIND_FASTING, 'value' => 90, 'unit' => 'mg/dL'], $owner);
        $recorder->recordGlucose($encounter, ['kind' => ClinicalGlucoseReading::KIND_HBA1C, 'value' => 6.4, 'unit' => '%'], $owner);

        $model = app(ClinicalSummary::class)->forPatient($patient);

        $this->assertNotNull($model['latestVital']);
        $this->assertSame('130/85', $model['latestVital']->bloodPressureLabel());
        $this->assertCount(1, $model['vitalSeries']['bloodPressure']);
        $this->assertCount(1, $model['vitalSeries']['weight']);

        // Each glucose kind is its own series with its own unit — fasting and
        // HbA1c are never merged into one misleading line.
        $this->assertSame('mg/dL', $model['glucoseSeries'][ClinicalGlucoseReading::KIND_FASTING]['unit']);
        $this->assertSame('%', $model['glucoseSeries'][ClinicalGlucoseReading::KIND_HBA1C]['unit']);
        $this->assertCount(1, $model['glucoseSeries'][ClinicalGlucoseReading::KIND_FASTING]['points']);
        $this->assertCount(0, $model['glucoseSeries'][ClinicalGlucoseReading::KIND_RANDOM]['points']);
        $this->assertCount(1, $model['glucoseSeries'][ClinicalGlucoseReading::KIND_HBA1C]['points']);
    }

    // ----------------------------------------------- Phase 6 authorization

    public function test_clinical_routes_enforce_permission_and_branch_scope(): void
    {
        [$tenant, $branchId] = $this->makeTenant();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $consultant = User::factory()->role(RoleKey::CONSULTANT)->create();
        $operator = User::factory()->role(RoleKey::LBA_OPERATOR)->create();
        $receptionist = User::factory()->role(RoleKey::RECEPTIONIST)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);
        $this->giveMembership($consultant, $tenant, [RoleKey::CONSULTANT]);
        $this->giveMembership($operator, $tenant, [RoleKey::LBA_OPERATOR]);
        $this->giveMembership($receptionist, $tenant, [RoleKey::RECEPTIONIST]);

        $this->assignStaff($owner, $consultant, $branchId);
        $this->assignStaff($owner, $operator, $branchId);
        $this->assignStaff($owner, $receptionist, $branchId);

        $this->tenantDatabases()->configure($tenant->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'Akses Pasien', 'status' => Patient::STATUS_ACTIVE], $owner);
        $encounter = app(ClinicalEncounterRegistrar::class)->open($patient, ['branch_id' => $branchId, 'type' => ClinicalEncounter::TYPE_NEW], $owner);

        $detail = route('clinic.tailadmin.patients.encounters.show', [$patient, $encounter]);
        $measure = route('clinic.tailadmin.patients.encounters.measurements.store', [$patient, $encounter, 'vitals']);

        // A clinician with the clinical permission opens the visit.
        $this->actingAs($consultant)->get($detail)->assertOk()->assertSee('Akses Pasien');

        // The receptionist manages administration only — no clinical access.
        $this->actingAs($receptionist)->get($detail)->assertForbidden();
        $this->actingAs($receptionist)->post($measure, ['systolic' => 120, 'diastolic' => 80])->assertForbidden();

        // The LBA operator may READ the record but may not write clinical data.
        $this->actingAs($operator)->get($detail)->assertOk();
        $this->actingAs($operator)->post($measure, ['systolic' => 120, 'diastolic' => 80])->assertForbidden();

        // A clinical write is accepted for the authorized clinician.
        $this->actingAs($consultant)->post($measure, ['systolic' => 122, 'diastolic' => 81])->assertRedirect($detail);
    }

    public function test_records_cannot_be_reached_across_patients_or_tenants(): void
    {
        [$tenant, $branchId] = $this->makeTenant();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $consultant = User::factory()->role(RoleKey::CONSULTANT)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);
        $this->giveMembership($consultant, $tenant, [RoleKey::CONSULTANT]);
        $this->assignStaff($owner, $consultant, $branchId);

        $this->tenantDatabases()->configure($tenant->id);
        $patientA = app(PatientRegistrar::class)->create(['full_name' => 'Pasien A', 'status' => Patient::STATUS_ACTIVE], $owner);
        $patientB = app(PatientRegistrar::class)->create(['full_name' => 'Pasien B', 'status' => Patient::STATUS_ACTIVE], $owner);
        $encounterB = app(ClinicalEncounterRegistrar::class)->open($patientB, ['branch_id' => $branchId, 'type' => ClinicalEncounter::TYPE_NEW], $owner);

        // A second tenant, with its own isolated database and clinical record.
        [$tenantTwo, $branchTwoId] = $this->makeTenant();
        $ownerTwo = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($ownerTwo, $tenantTwo, [RoleKey::TENANT_OWNER]);
        $this->tenantDatabases()->configure($tenantTwo->id);
        $patientTwo = app(PatientRegistrar::class)->create(['full_name' => 'Pasien Tenant Lain', 'status' => Patient::STATUS_ACTIVE], $ownerTwo);
        $encounterTwo = app(ClinicalEncounterRegistrar::class)->open($patientTwo, ['branch_id' => $branchTwoId, 'type' => ClinicalEncounter::TYPE_NEW], $ownerTwo);

        // A visit that belongs to patient B never resolves under patient A.
        $this->actingAs($consultant)
            ->get(route('clinic.tailadmin.patients.encounters.show', [$patientA, $encounterB]))
            ->assertNotFound();

        // A record in another tenant's database never resolves at all.
        $this->actingAs($consultant)
            ->get(route('clinic.tailadmin.patients.show', [$patientTwo]))
            ->assertNotFound();
        $this->actingAs($consultant)
            ->get(route('clinic.tailadmin.patients.encounters.show', [$patientTwo, $encounterTwo]))
            ->assertNotFound();
    }

    // ------------------------------------------------- Phase 4 LBA handoff

    public function test_lba_examination_opens_from_an_encounter_without_duplicating_the_patient(): void
    {
        [$tenant, $branchId] = $this->makeTenant();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $consultant = User::factory()->role(RoleKey::CONSULTANT)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);
        $this->giveMembership($consultant, $tenant, [RoleKey::CONSULTANT]);
        $this->assignStaff($owner, $consultant, $branchId);

        $this->tenantDatabases()->configure($tenant->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'Handoff LBA', 'status' => Patient::STATUS_ACTIVE], $owner);
        $encounter = app(ClinicalEncounterRegistrar::class)->open($patient, ['branch_id' => $branchId, 'type' => ClinicalEncounter::TYPE_NEW], $owner);

        $open = route('clinic.tailadmin.patients.encounters.lba.store', [$patient, $encounter]);

        $this->actingAs($consultant)->post($open, ['record_consent' => '1'])->assertRedirect();

        $this->tenantDatabases()->configure($tenant->id);
        $examination = Examination::query()->where('clinical_encounter_id', $encounter->id)->sole();
        $this->assertSame($patient->id, $examination->patient_id);
        $this->assertSame($branchId, $examination->branch_id);
        $this->assertSame(1, Examination::query()->count());

        // Pressing it again is idempotent — no duplicate case, no duplicate patient.
        $this->actingAs($consultant)->post($open, ['record_consent' => '1'])->assertRedirect();
        $this->tenantDatabases()->configure($tenant->id);
        $this->assertSame(1, Examination::query()->count());
        $this->assertSame(1, Patient::query()->where('full_name', 'Handoff LBA')->count());
        $this->assertSame(1, Examination::query()->where('id', $examination->id)->count());
    }

    public function test_the_lba_operator_cannot_open_an_examination_from_an_encounter(): void
    {
        [$tenant, $branchId] = $this->makeTenant();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $operator = User::factory()->role(RoleKey::LBA_OPERATOR)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);
        $this->giveMembership($operator, $tenant, [RoleKey::LBA_OPERATOR]);
        $this->assignStaff($owner, $operator, $branchId);

        $this->tenantDatabases()->configure($tenant->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'Operator LBA', 'status' => Patient::STATUS_ACTIVE], $owner);
        $encounter = app(ClinicalEncounterRegistrar::class)->open($patient, ['branch_id' => $branchId, 'type' => ClinicalEncounter::TYPE_NEW], $owner);

        // The operator holds only clinical.record.read — the handoff is a manage action.
        $this->actingAs($operator)
            ->post(route('clinic.tailadmin.patients.encounters.lba.store', [$patient, $encounter]))
            ->assertForbidden();
    }

    // ------------------------------------------------------------ Phase 6 AI

    public function test_a_machine_identity_cannot_touch_clinical_records(): void
    {
        [$tenant, $branchId] = $this->makeTenant();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);

        $this->tenantDatabases()->configure($tenant->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'Pasien AI', 'status' => Patient::STATUS_ACTIVE], $owner);
        $encounter = app(ClinicalEncounterRegistrar::class)->open($patient, ['branch_id' => $branchId, 'type' => ClinicalEncounter::TYPE_NEW], $owner);

        // The AI service identity is machine-only: the platform refuses to
        // grant it a tenant membership (see RoleAssignmentScopeTest), so it has
        // no clinic panel and holds no human clinical permission at all.
        $service = User::factory()->role(RoleKey::AI_SERVICE)->create();

        $detail = route('clinic.tailadmin.patients.encounters.show', [$patient, $encounter]);

        $this->actingAs($service)->get($detail)->assertForbidden();
        $this->actingAs($service)
            ->post(route('clinic.tailadmin.patients.encounters.finalize', [$patient, $encounter]))
            ->assertForbidden();

        // The encounter remains a draft — the machine identity neither signed it
        // off nor changed its assessment.
        $this->tenantDatabases()->configure($tenant->id);
        $this->assertTrue($encounter->refresh()->isDraft());
    }

    // ------------------------------------------------------------- Phase 5

    public function test_the_patient_profile_and_visit_pages_render_for_desktop_and_mobile(): void
    {
        [$tenant, $branchId] = $this->makeTenant();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $consultant = User::factory()->role(RoleKey::CONSULTANT)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);
        $this->giveMembership($consultant, $tenant, [RoleKey::CONSULTANT]);
        $this->assignStaff($owner, $consultant, $branchId);

        $this->tenantDatabases()->configure($tenant->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'Tampilan Pasien', 'status' => Patient::STATUS_ACTIVE], $owner);
        app(ClinicalProfileRegistrar::class)->addCondition($patient, ['name' => 'Hipertensi', 'status' => PatientCondition::STATUS_ACTIVE], $owner);
        $encounter = app(ClinicalEncounterRegistrar::class)->open($patient, ['branch_id' => $branchId, 'type' => ClinicalEncounter::TYPE_NEW, 'chief_complaint' => 'Pusing'], $owner);
        app(ClinicalMeasurementRecorder::class)->recordVitals($encounter, ['systolic' => 140, 'diastolic' => 90], $owner);

        $this->actingAs($consultant)
            ->get(route('clinic.tailadmin.patients.show', [$patient]))
            ->assertOk()
            ->assertSee('Tampilan Pasien');

        // Every tab renders (desktop and mobile share the same responsive markup).
        foreach (['summary', 'history', 'encounters', 'trends', 'lba'] as $tab) {
            $this->actingAs($consultant)
                ->get(route('clinic.tailadmin.patients.show', ['patient' => $patient, 'tab' => $tab]))
                ->assertOk();
        }

        $this->actingAs($consultant)
            ->get(route('clinic.tailadmin.patients.encounters.show', [$patient, $encounter]))
            ->assertOk()
            ->assertSee('Pusing')
            ->assertSee('SOAP');
    }

    // ---------------------------------------------------- Phase 4 reliability

    public function test_encounter_registrar_never_overwrites_an_earlier_visit(): void
    {
        [$tenant, $branchId] = $this->makeTenant();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);

        $this->tenantDatabases()->configure($tenant->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'Riwayat', 'status' => Patient::STATUS_ACTIVE], $owner);

        $registrar = app(ClinicalEncounterRegistrar::class);
        $first = $registrar->open($patient, ['branch_id' => $branchId, 'type' => ClinicalEncounter::TYPE_NEW, 'assessment' => 'Kunjungan pertama'], $owner);
        $second = $registrar->open($patient, ['branch_id' => $branchId, 'type' => ClinicalEncounter::TYPE_FOLLOW_UP, 'assessment' => 'Kunjungan kedua'], $owner);

        // Editing the newer visit leaves the older one exactly as it was written.
        $registrar->updateDraft($second, ['assessment' => 'Kunjungan kedua (diperbarui)'], $owner);

        $this->assertSame('Kunjungan pertama', $first->refresh()->assessment);
        $this->assertSame('Kunjungan kedua (diperbarui)', $second->refresh()->assessment);
        $this->assertSame(2, ClinicalEncounter::query()->where('patient_id', $patient->id)->count());
    }

    public function test_lba_handoff_reuses_the_existing_intake_service(): void
    {
        [$tenant, $branchId] = $this->makeTenant();
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);

        $this->tenantDatabases()->configure($tenant->id);
        $patient = app(PatientRegistrar::class)->create(['full_name' => 'Intake LBA', 'status' => Patient::STATUS_ACTIVE], $owner);
        $encounter = app(ClinicalEncounterRegistrar::class)->open($patient, ['branch_id' => $branchId, 'type' => ClinicalEncounter::TYPE_NEW], $owner);

        $registrar = app(ExaminationRegistrar::class);
        $examination = $registrar->intakeFromClinicalEncounter($encounter, $owner, ['record_consent' => true]);

        $this->assertSame($encounter->id, $examination->clinical_encounter_id);
        $this->assertSame(Examination::STATUS_DRAFT, $examination->status);
        $this->assertNotNull($examination->consent_id);

        // Idempotent on the encounter: the second call returns the same case.
        $again = $registrar->intakeFromClinicalEncounter($encounter, $owner, ['record_consent' => true]);
        $this->assertTrue($examination->is($again));
    }
}
