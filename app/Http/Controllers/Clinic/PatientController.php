<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Clinic\Concerns\AuthorizesClinicalRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\SavePatientRequest;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PermissionKey;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Appointments\Models\Appointment;
use Modules\Consultations\Filament\Resources\ConsultationResource;
use Modules\Consultations\Services\SinaWalletService;
use Modules\LBA\Models\Examination;
use Modules\Patients\Exceptions\PatientLinkException;
use Modules\Patients\Filament\Resources\PatientResource;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\ClinicalSummary;
use Modules\Patients\Services\PatientPortalPasswordSetter;
use Modules\Patients\Services\PatientRegistrar;

/**
 * Pasien — TailAdmin patient registry (Phase 11).
 *
 * Replaces the "open patient → Filament" escape hatch with a first-class
 * TailAdmin surface: the patient profile, its registration and status, and the
 * operational lifecycle (register, edit, archive, restore) are handled here
 * against the verified tenant's own isolated database (the {@see Patient}
 * model resolves that connection and fails closed without it).
 *
 * Authorization re-derives the canonical `patient.profile.read` /
 * `patient.profile.manage` permissions server-side through
 * {@see PatientResource} (never from client input), and every write goes
 * through {@see PatientRegistrar} so the audit trail is preserved. Patients are
 * archived, never deleted, so a patient's history across every branch of the
 * tenant survives.
 */
final class PatientController extends Controller
{
    use AuthorizesClinicalRecords;

    public function create(Request $request, ManagesTenantContext $tenants, ManagesBranchContext $branches): View
    {
        abort_unless(PatientResource::canCreate(), 403);

        $user = $request->user();

        return view('clinic.tailadmin.patient-create', [
            'tenantId' => $tenants->currentTenantId($user),
            'options' => $branches->eligibleOptions($user),
            'branchId' => $branches->currentBranchId($user),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function store(SavePatientRequest $request, PatientRegistrar $registrar): RedirectResponse
    {
        $registrar->create($request->validated() + ['registered_at' => now()], $request->user());

        return to_route('clinic.tailadmin.index', 'patients')
            ->with('success', __('lba.patients.created'));
    }

    /**
     * The patient profile: identity, contact, registration and (when the
     * Appointments module is present) the appointment history.
     */
    public function show(
        Request $request,
        ClinicalSummary $summary,
        ManagesTenantContext $tenants,
        SinaWalletService $sinaWallet,
        string $patient,
    ): View {
        abort_unless(PatientResource::canViewAny(), 403);

        $patient = $this->resolvePatient($patient);
        $canReadClinical = $this->clinicalAllows(PermissionKey::CLINICAL_RECORD_READ);
        $tenantId = $tenants->currentTenantId($request->user());
        $canManageSinaCredits = $this->clinicalAllows(PermissionKey::WALLET_MANAGE);

        return view('clinic.tailadmin.patient-show', [
            'patient' => $patient,
            'appointments' => $this->appointments($patient),
            'canManage' => PatientResource::canCreate(),
            'canReadClinical' => $canReadClinical,
            'canManageClinical' => $this->clinicalAllows(PermissionKey::CLINICAL_RECORD_MANAGE),
            'canFinalizeClinical' => $this->clinicalAllows(PermissionKey::CLINICAL_ENCOUNTER_FINALIZE),
            'clinical' => $canReadClinical ? $summary->forPatient($patient) : null,
            'lbaExaminations' => $this->lbaExaminations($patient),
            'sinaCredits' => $tenantId === null ? 0 : $sinaWallet->balanceFor($tenantId, (string) $patient->getKey()),
            'sinaGrantKey' => (string) Str::ulid(),
            'canManageSinaCredits' => $canManageSinaCredits,
            'canViewSina' => ConsultationResource::canViewAny(),
            'activeTab' => $this->activeTab($request),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function grantSinaCredits(
        Request $request,
        ManagesTenantContext $tenants,
        SinaWalletService $wallet,
        string $patient,
    ): RedirectResponse {
        $this->authorizeClinical(PermissionKey::WALLET_MANAGE);
        $patientRecord = $this->resolvePatient($patient);
        $tenantId = $tenants->currentTenantId($request->user());

        abort_unless($tenantId !== null, 404);

        $data = $request->validate([
            'credits' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['required', 'string', 'max:160'],
            'grant_key' => ['required', 'ulid'],
        ]);

        $wallet->grant(
            tenantId: $tenantId,
            patientId: (string) $patientRecord->getKey(),
            units: (int) $data['credits'],
            reason: trim($data['reason']),
            idempotencyKey: 'clinic-topup:'.$tenantId.':'.$patientRecord->getKey().':'.$data['grant_key'],
            actorReference: (string) $request->user()->getKey(),
        );

        return to_route('clinic.tailadmin.patients.show', $patientRecord)
            ->with('success', __('lba.sina.credits_granted'));
    }

    /**
     * Reset the linked portal account password. Only an identity holding
     * `patient.profile.manage` for the verified tenant may do this; the new
     * value is treated as temporary (`must_set_password`), so the patient must
     * choose their own password on next sign-in.
     */
    public function resetPortalPassword(
        Request $request,
        PatientPortalPasswordSetter $passwordSetter,
        string $patient,
    ): RedirectResponse {
        abort_unless(PatientResource::canCreate(), 403);

        $patientRecord = $this->resolvePatient($patient);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        try {
            $passwordSetter->set(
                patient: $patientRecord,
                newPassword: (string) $data['password'],
                actor: $request->user(),
            );
        } catch (PatientLinkException $e) {
            return back()->withErrors(['password' => $e->getMessage()]);
        }

        return to_route('clinic.tailadmin.patients.show', $patientRecord)
            ->with('success', __('lba.patients.portal_password_reset'));
    }

    /**
     * The patient-detail tab requested, constrained to the known set. An
     * unknown value falls back to the summary tab instead of erroring.
     */
    private function activeTab(Request $request): string
    {
        $tabs = ['summary', 'history', 'encounters', 'trends', 'lba'];
        $tab = (string) $request->query('tab', 'summary');

        return in_array($tab, $tabs, true) ? $tab : 'summary';
    }

    public function edit(Request $request, string $patient, ManagesTenantContext $tenants, ManagesBranchContext $branches): View
    {
        abort_unless(PatientResource::canCreate(), 403);

        $patient = $this->resolvePatient($patient);
        $user = $request->user();

        return view('clinic.tailadmin.patient-edit', [
            'patient' => $patient,
            'tenantId' => $tenants->currentTenantId($user),
            'options' => $branches->eligibleOptions($user),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function update(SavePatientRequest $request, string $patient, PatientRegistrar $registrar): RedirectResponse
    {
        $registrar->update($this->resolvePatient($patient), $request->validated(), $request->user());

        return to_route('clinic.tailadmin.patients.show', $patient)
            ->with('success', __('lba.patients.updated'));
    }

    public function archive(Request $request, string $patient, PatientRegistrar $registrar): RedirectResponse
    {
        abort_unless(PatientResource::canCreate(), 403);

        $registrar->archive($this->resolvePatient($patient), $request->user());

        return to_route('clinic.tailadmin.patients.show', $patient)
            ->with('success', __('lba.patients.archived'));
    }

    public function restore(Request $request, string $patient, PatientRegistrar $registrar): RedirectResponse
    {
        abort_unless(PatientResource::canCreate(), 403);

        $registrar->restore($this->resolvePatient($patient), $request->user());

        return to_route('clinic.tailadmin.patients.show', $patient)
            ->with('success', __('lba.patients.restored'));
    }

    /**
     * Resolve the target patient from the VERIFIED tenant database.
     *
     * Implicit route-model binding cannot be used here: `SubstituteBindings`
     * runs before the tenant connection is bound, so a `Patient` would fail
     * closed. The id is resolved explicitly inside the request instead — a
     * foreign or unknown id is simply not found (404), never widened.
     */
    private function resolvePatient(string $patient): Patient
    {
        return Patient::query()->whereKey($patient)->firstOrFail();
    }

    /**
     * The patient's appointment history, resolved only when the Appointments
     * module is installed (the Patients module never hard-depends on it).
     *
     * @return iterable<int, Appointment>
     */
    private function appointments(string $patient): iterable
    {
        if (! class_exists(Appointment::class)) {
            return collect();
        }

        return Appointment::query()
            ->with('branch')
            ->where('patient_id', $patient)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * The patient's LBA examination history, resolved only when the LBA module
     * is installed (the Patients module never hard-depends on it). This is a
     * read-only listing — starting an examination still goes through the LBA
     * module's own workflow and consent gates.
     *
     * @return iterable<int, Examination>
     */
    private function lbaExaminations(string $patient): iterable
    {
        if (! class_exists(Examination::class)) {
            return collect();
        }

        return Examination::query()
            ->where('patient_id', $patient)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }
}
