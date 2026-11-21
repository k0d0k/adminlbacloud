<?php

namespace Tests\Feature\Filament;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Patients\Filament\Resources\PatientResource\Pages\ListPatients;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientAuditLog;
use Modules\Patients\Services\PatientAccountLinker;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\Support\DrivesPrincipalPanel;
use Tests\TestCase;

/**
 * Clinic panel — reset a patient's portal password from the registry.
 *
 * Verifies the Filament "Atur Ulang Kata Sandi Portal" action on the patients
 * table is wired to the authorized PatientPortalPasswordSetter service: the
 * clinic-chosen password replaces the linked account's, the account is flagged
 * `must_set_password`, the value is never echoed, and the change is audited.
 * Authorization itself is re-derived server-side (asserted in
 * tests/Feature/ClinicTailAdminTest.php); this test covers the panel wiring.
 */
class ClinicPatientPortalPasswordTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, DrivesPrincipalPanel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
        Filament::setCurrentPanel(Filament::getPanel('clinic'));
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    private function owner(Tenant $tenant): User
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        return $user;
    }

    private function activatePatients(Tenant $tenant): void
    {
        RegisteredModule::factory()->forKey('patients')->enabled()->create();
        ModuleInstallation::factory()->forModule('patients')->forScope(ModuleScope::TENANT, $tenant->id)->enabled()->create();
        ModuleEntitlement::factory()->forModule('patients')->forScope(ModuleScope::TENANT, $tenant->id)->granted()->create();
    }

    public function test_a_clinic_owner_resets_a_linked_patients_portal_password_from_the_registry(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->owner($tenant);
        $this->provisionTenantDatabase($tenant);
        $this->activatePatients($tenant);

        $this->tenantDatabases()->configure($tenant->id);
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Sandi']);

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Panel Password Patient',
            'status' => Patient::STATUS_ACTIVE,
            'registered_branch_id' => $branch->id,
        ], $owner);

        // Bind a portal account the way production does.
        $account = User::factory()->create();
        $code = app(PatientAccountLinker::class)->issueInvitation($patient, $owner);
        app(PatientAccountLinker::class)->redeemInvitation($account, $code);

        $this->assertTrue($patient->fresh()->isLinked());

        $newPassword = 'PanelPortalPass123!';

        $this->actingAs($owner);

        // The read-only patient page renders and exposes the same action.
        $this->get('/clinic/patients/'.$patient->getKey())->assertOk();

        $response = $this->postLivewireCalls($this->renderPage('/clinic/patients'), ListPatients::class, [
            ['method' => 'mountAction', 'params' => ['reset_portal_password', [], ['table' => true, 'recordKey' => $patient->getKey()]]],
            ['method' => 'fillFormDataForTesting', 'params' => [[
                'mountedActions.0.data.password' => $newPassword,
                'mountedActions.0.data.password_confirmation' => $newPassword,
            ], 'mountedActions.0.data']],
            ['method' => 'callMountedAction', 'params' => []],
        ]);

        // The chosen password is never echoed back into the response.
        $response->assertOk()->assertDontSee($newPassword);

        $account->refresh();
        $this->assertTrue(Hash::check($newPassword, $account->getAuthPassword()));
        $this->assertTrue($account->must_set_password);

        $this->tenantDatabases()->configure($tenant->id);
        $this->assertTrue(
            PatientAuditLog::query()
                ->where('patient_id', $patient->getKey())
                ->where('action', PatientAuditLog::ACTION_PORTAL_PASSWORD_SET)
                ->exists()
        );
    }
}
