<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Branches\Models\TenantBranch;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Consultations\Exceptions\ConsultationException;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Models\ConsultationAuditLog;
use Modules\Consultations\Services\ConsultationManager;
use Modules\Consultations\Services\SinaConsentService;
use Modules\Consultations\Services\SinaEscalation;
use Modules\Consultations\Services\SinaWalletService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Services\PatientRegistrar;
use Modules\Practitioners\Models\Practitioner;
use Modules\Practitioners\Services\PractitionerRegistry;
use Modules\Practitioners\Services\PractitionerScheduleRegistry;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 13 — escalate a SINA AI chat session to a human expert consultation.
 *
 * Proves the bridge preserves the existing human flow (scheduling +
 * practitioner authorization), adds the two fail-closed gates (SINA session +
 * explicit expert-sharing consent) and keeps the SINA credit ledger completely
 * separate from the human consultation. Only synthetic data is used.
 */
class SinaEscalationTest extends TestCase
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
        Carbon::setTestNow();

        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    // --------------------------------------------------------------- Helpers

    private function member(Tenant $tenant, string $roleKey): User
    {
        $user = User::factory()->role($roleKey)->create();
        $this->giveMembership($user, $tenant, [$roleKey]);

        return $user;
    }

    private function bindTenant(Tenant $tenant): void
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function tenantWithOwner(): array
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        return [$tenant, $owner];
    }

    /**
     * A verified, branch-authorized practitioner with a Monday window covering
     * the escalation slot. Returns the practitioner (its `user_id` is the staff
     * identity the consultation is assigned to).
     */
    private function readyPractitioner(Tenant $tenant, User $owner, TenantBranch $branch): Practitioner
    {
        $clinical = $this->member($tenant, RoleKey::CONSULTANT);
        $this->bindTenant($tenant);

        $registry = app(PractitionerRegistry::class);

        $practitioner = $registry->register($owner, (string) $clinical->getKey(), [
            'display_name' => 'Dr Escalation',
        ]);
        $registry->verify($owner, $practitioner);
        $registry->authorizeBranch($owner, $practitioner, (string) $branch->getKey());

        app(PractitionerScheduleRegistry::class)->addWindow($owner, $practitioner, [
            'branch_id' => (string) $branch->getKey(),
            'weekday' => Carbon::MONDAY,
            'starts_at' => '09:00',
            'ends_at' => '17:00',
        ]);

        return $practitioner->fresh();
    }

    private function patient(Tenant $tenant, User $owner): Patient
    {
        $this->bindTenant($tenant);

        return app(PatientRegistrar::class)->create([
            'full_name' => 'Synthetic Escalation Patient',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);
    }

    private function sinaSession(Tenant $tenant, User $owner, Patient $patient, TenantBranch $branch): Consultation
    {
        $this->bindTenant($tenant);

        return app(ConsultationManager::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'channel' => Consultation::CHANNEL_SINA_CHAT,
            'type' => Consultation::TYPE_CONSULTATION,
            'status' => Consultation::STATUS_IN_PROGRESS,
        ], $owner);
    }

    private function grantExpertSharing(Patient $patient, User $owner): PatientConsent
    {
        return app(SinaConsentService::class)->grantExpertSharing($patient, $owner, 'test');
    }

    private function futureMonday(): Carbon
    {
        return Carbon::now('UTC')->addWeek()->startOfWeek(Carbon::MONDAY)->setTime(12, 0);
    }

    // --------------------------------------------------------------- Escalation

    public function test_a_sina_session_escalates_to_a_human_expert_consultation(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $branch = $this->createBranch($tenant, ['timezone' => 'UTC']);
        $practitioner = $this->readyPractitioner($tenant, $owner, $branch);
        $patient = $this->patient($tenant, $owner);
        $session = $this->sinaSession($tenant, $owner, $patient, $branch);
        $this->grantExpertSharing($patient, $owner);

        $this->bindTenant($tenant);

        $human = app(SinaEscalation::class)->escalate(
            $session,
            $branch,
            (string) $practitioner->getKey(),
            ['scheduled_at' => $this->futureMonday()->toIso8601String()],
            $owner,
        );

        // A NEW human consultation, linked back to the SINA session.
        $this->assertNotSame($session->getKey(), $human->getKey());
        $this->assertSame(Consultation::CHANNEL_TELECONSULT, $human->channel);
        $this->assertSame($session->getKey(), $human->escalated_from_id);
        $this->assertSame($patient->getKey(), $human->patient_id);
        $this->assertSame((string) $practitioner->user_id, (string) $human->staff_user_id);
        $this->assertNotNull($human->appointment_id);

        // Both sides of the bridge are audited.
        $this->assertSame(1, ConsultationAuditLog::query()
            ->where('consultation_id', $human->getKey())
            ->where('action', ConsultationAuditLog::ACTION_ESCALATED)
            ->count());
        $this->assertSame(1, ConsultationAuditLog::query()
            ->where('consultation_id', $session->getKey())
            ->where('action', ConsultationAuditLog::ACTION_ESCALATED)
            ->count());
    }

    public function test_escalation_requires_expert_sharing_consent(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $branch = $this->createBranch($tenant, ['timezone' => 'UTC']);
        $practitioner = $this->readyPractitioner($tenant, $owner, $branch);
        $patient = $this->patient($tenant, $owner);
        $session = $this->sinaSession($tenant, $owner, $patient, $branch);

        // No expert-sharing consent was recorded.
        $this->bindTenant($tenant);

        try {
            app(SinaEscalation::class)->escalate(
                $session,
                $branch,
                (string) $practitioner->getKey(),
                ['scheduled_at' => $this->futureMonday()->toIso8601String()],
                $owner,
            );
            $this->fail('Escalation without expert-sharing consent must be refused.');
        } catch (ConsultationException $exception) {
            $this->assertSame('expert_sharing_consent_required', $exception->errorCode());
        }

        // No human consultation was created.
        $this->bindTenant($tenant);
        $this->assertSame(1, Consultation::query()->count());
    }

    public function test_only_a_sina_session_can_be_escalated(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $branch = $this->createBranch($tenant, ['timezone' => 'UTC']);
        $practitioner = $this->readyPractitioner($tenant, $owner, $branch);
        $patient = $this->patient($tenant, $owner);
        $this->grantExpertSharing($patient, $owner);

        $this->bindTenant($tenant);

        $inPerson = app(ConsultationManager::class)->create([
            'patient_id' => $patient->getKey(),
            'branch_id' => $branch->getKey(),
            'channel' => Consultation::CHANNEL_IN_PERSON,
            'status' => Consultation::STATUS_IN_PROGRESS,
        ], $owner);

        try {
            app(SinaEscalation::class)->escalate(
                $inPerson,
                $branch,
                (string) $practitioner->getKey(),
                ['scheduled_at' => $this->futureMonday()->toIso8601String()],
                $owner,
            );
            $this->fail('Only a SINA session may be escalated.');
        } catch (ConsultationException $exception) {
            $this->assertSame('not_sina_session', $exception->errorCode());
        }
    }

    public function test_escalation_requires_a_named_practitioner(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $branch = $this->createBranch($tenant, ['timezone' => 'UTC']);
        $patient = $this->patient($tenant, $owner);
        $session = $this->sinaSession($tenant, $owner, $patient, $branch);
        $this->grantExpertSharing($patient, $owner);

        $this->bindTenant($tenant);

        try {
            app(SinaEscalation::class)->escalate($session, $branch, '', [], $owner);
            $this->fail('Escalation without a practitioner must be refused.');
        } catch (ConsultationException $exception) {
            $this->assertSame('practitioner_required', $exception->errorCode());
        }
    }

    public function test_escalation_never_touches_the_sina_credit_wallet(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $branch = $this->createBranch($tenant, ['timezone' => 'UTC']);
        $practitioner = $this->readyPractitioner($tenant, $owner, $branch);
        $patient = $this->patient($tenant, $owner);
        $session = $this->sinaSession($tenant, $owner, $patient, $branch);
        $this->grantExpertSharing($patient, $owner);

        $wallet = app(SinaWalletService::class);
        $tenantId = (string) $tenant->getKey();
        $patientId = (string) $patient->getKey();
        $wallet->grant($tenantId, $patientId, 50, 'synthetic', 'g-esc-1');

        $this->bindTenant($tenant);

        app(SinaEscalation::class)->escalate(
            $session,
            $branch,
            (string) $practitioner->getKey(),
            ['scheduled_at' => $this->futureMonday()->toIso8601String()],
            $owner,
        );

        // Credit separation: the human consultation is metered by its own
        // channel tariff, never by the SINA wallet.
        $stored = $wallet->walletFor($tenantId, $patientId);
        $this->assertSame(50, $wallet->balanceFor($tenantId, $patientId));
        $this->assertSame(50, (int) $stored->balance_credits);
        $this->assertSame(0, (int) $stored->reserved_credits);
        $this->assertSame(0, (int) $stored->lifetime_consumed_credits);
    }
}
