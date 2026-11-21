<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Appointments\Exceptions\AppointmentConflictException;
use Modules\Appointments\Exceptions\PreregistrationException;
use Modules\Appointments\Exceptions\WaitlistException;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Models\EventPreregistration;
use Modules\Appointments\Models\WaitlistEntry;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\Appointments\Services\BookingWaitlist;
use Modules\Appointments\Services\EventPreregistrationService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Phase 10 — booking capacity, expiring holds, waitlist and event
 * preregistration (focused essentials).
 *
 * Only synthetic identities, branches and patients are used (DECISIONS.md
 * PRV-01). The capacity/overlap guards live in {@see AppointmentScheduler}; the
 * expiring hold, the waitlist and the tentative-event preregistration are the
 * patient-flow surfaces under test.
 */
class BookingCapacityTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'appointments';

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

    private function member(Tenant $tenant, string $roleKey, string $status = TenantMembership::STATUS_ACTIVE): User
    {
        $user = User::factory()->role($roleKey)->create();
        $this->giveMembership($user, $tenant, [$roleKey], $status);

        return $user;
    }

    private function bindTenant(Tenant $tenant): void
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());
    }

    private function activate(Tenant $tenant): void
    {
        RegisteredModule::factory()->forKey(self::MODULE)->enabled()->create();

        ModuleInstallation::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->enabled()
            ->create();

        ModuleEntitlement::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->granted()
            ->create();
    }

    private function patient(Tenant $tenant, User $owner, string $name = 'Synthetic Capacity Patient'): Patient
    {
        $this->bindTenant($tenant);

        return app(PatientRegistrar::class)->create([
            'full_name' => $name,
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);
    }

    /**
     * @return array{0: Tenant, 1: User, 2: TenantBranch}
     */
    private function preparedClinic(): array
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->activate($tenant);

        $branch = $this->createBranch($tenant, ['capacity' => 1]);

        return [$tenant, $owner, $branch];
    }

    // -------------------------------------------------------------- Capacity

    public function test_parallel_patients_cannot_exceed_the_last_slot(): void
    {
        [$tenant, $owner, $branch] = $this->preparedClinic();
        $this->bindTenant($tenant);

        $starts = now()->addDay()->setTime(9, 0);
        $window = ['starts_at' => $starts, 'ends_at' => $starts->copy()->addHour()];

        $first = $this->patient($tenant, $owner, 'Patient One');
        $second = $this->patient($tenant, $owner, 'Patient Two');

        $scheduler = app(AppointmentScheduler::class);

        $scheduler->create($window + [
            'patient_id' => $first->getKey(),
            'branch_id' => $branch->getKey(),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ], $owner);

        try {
            $scheduler->create($window + [
                'patient_id' => $second->getKey(),
                'branch_id' => $branch->getKey(),
                'service_type' => Appointment::SERVICE_CONSULTATION,
            ], $owner);

            $this->fail('A second overlapping booking was accepted past capacity.');
        } catch (AppointmentConflictException $exception) {
            $this->assertSame('branch_at_capacity', $exception->errorCode());
        }
    }

    // ------------------------------------------------------------- Slot hold

    public function test_a_live_hold_occupies_the_slot_until_it_expires(): void
    {
        [$tenant, $owner, $branch] = $this->preparedClinic();
        $this->bindTenant($tenant);

        $starts = now()->addDay()->setTime(9, 0);
        $window = ['starts_at' => $starts, 'ends_at' => $starts->copy()->addHour()];

        $holder = $this->patient($tenant, $owner, 'Hold Patient');
        $walkerIn = $this->patient($tenant, $owner, 'Walk-in Patient');

        $scheduler = app(AppointmentScheduler::class);

        $hold = $scheduler->hold($window + [
            'patient_id' => $holder->getKey(),
            'branch_id' => $branch->getKey(),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ], $owner, 15);

        $this->assertSame(Appointment::STATUS_HELD, $hold->status);
        $this->assertTrue($hold->hasActiveHold());

        // A live hold still blocks the last slot.
        try {
            $scheduler->create($window + [
                'patient_id' => $walkerIn->getKey(),
                'branch_id' => $branch->getKey(),
                'service_type' => Appointment::SERVICE_CONSULTATION,
            ], $owner);

            $this->fail('A live hold did not occupy the slot.');
        } catch (AppointmentConflictException $exception) {
            $this->assertSame('branch_at_capacity', $exception->errorCode());
        }

        // Once the deadline passes the slot frees itself, even before the sweep.
        $this->travel(20)->minutes();

        $this->assertFalse($hold->fresh()->hasActiveHold());

        $booking = $scheduler->create($window + [
            'patient_id' => $walkerIn->getKey(),
            'branch_id' => $branch->getKey(),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ], $owner);

        $this->assertSame(Appointment::STATUS_SCHEDULED, $booking->status);
    }

    public function test_the_expiry_sweep_cancels_only_lapsed_holds(): void
    {
        [$tenant, $owner, $branch] = $this->preparedClinic();
        $this->bindTenant($tenant);

        $scheduler = app(AppointmentScheduler::class);

        $starts = now()->addDay()->setTime(9, 0);
        $lapsed = $scheduler->hold([
            'patient_id' => $this->patient($tenant, $owner, 'Lapsed Holder')->getKey(),
            'branch_id' => $branch->getKey(),
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHour(),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ], $owner, 15);

        $this->travel(20)->minutes();

        $later = now()->addDay()->setTime(14, 0);
        $live = $scheduler->hold([
            'patient_id' => $this->patient($tenant, $owner, 'Live Holder')->getKey(),
            'branch_id' => $branch->getKey(),
            'starts_at' => $later,
            'ends_at' => $later->copy()->addHour(),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ], $owner, 15);

        $cancelled = $scheduler->expireHolds($owner);

        $this->assertSame(1, $cancelled);
        $this->assertSame(Appointment::STATUS_CANCELLED, $lapsed->fresh()->status);
        $this->assertSame(Appointment::STATUS_HELD, $live->fresh()->status);
    }

    public function test_confirming_a_live_hold_clears_its_deadline(): void
    {
        [$tenant, $owner, $branch] = $this->preparedClinic();
        $this->bindTenant($tenant);

        $starts = now()->addDay()->setTime(9, 0);

        $hold = app(AppointmentScheduler::class)->hold([
            'patient_id' => $this->patient($tenant, $owner)->getKey(),
            'branch_id' => $branch->getKey(),
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHour(),
            'service_type' => Appointment::SERVICE_CONSULTATION,
        ], $owner, 15);

        $confirmed = app(AppointmentScheduler::class)->confirmHold($hold, $owner);

        $this->assertSame(Appointment::STATUS_CONFIRMED, $confirmed->status);
        $this->assertNull($confirmed->hold_expires_at);
    }

    // -------------------------------------------------------------- Waitlist

    public function test_a_patient_can_join_the_waitlist_and_sees_their_place(): void
    {
        [$tenant, $owner, $branch] = $this->preparedClinic();
        $this->bindTenant($tenant);

        $waitlist = app(BookingWaitlist::class);

        $first = $this->patient($tenant, $owner, 'Queue One');
        $second = $this->patient($tenant, $owner, 'Queue Two');

        $entryOne = $waitlist->join((string) $branch->getKey(), (string) $first->getKey(), [], $owner);
        $entryTwo = $waitlist->join((string) $branch->getKey(), (string) $second->getKey(), [], $owner);

        $this->assertSame(WaitlistEntry::STATUS_WAITING, $entryOne->status);
        $this->assertSame(1, $waitlist->position($entryOne));
        $this->assertSame(2, $waitlist->position($entryTwo));

        // The same patient cannot hold two active places at one location.
        try {
            $waitlist->join((string) $branch->getKey(), (string) $first->getKey(), [], $owner);
            $this->fail('A duplicate active waitlist entry was accepted.');
        } catch (WaitlistException $exception) {
            $this->assertSame('already_waiting', $exception->errorCode());
        }
    }

    public function test_an_unclaimed_offer_lapses_and_frees_the_place(): void
    {
        [$tenant, $owner, $branch] = $this->preparedClinic();
        $this->bindTenant($tenant);

        $waitlist = app(BookingWaitlist::class);
        $entry = $waitlist->join((string) $branch->getKey(), (string) $this->patient($tenant, $owner)->getKey(), [], $owner);

        $offered = $waitlist->offer($entry, 30, $owner);
        $this->assertSame(WaitlistEntry::STATUS_OFFERED, $offered->status);
        $this->assertTrue($offered->offerIsLive());

        $this->travel(31)->minutes();

        $this->assertSame(1, $waitlist->expireOffers());
        $this->assertSame(WaitlistEntry::STATUS_EXPIRED, $offered->fresh()->status);
    }

    // ------------------------------------------------------- Preregistration

    public function test_preregistration_is_only_accepted_for_a_tentative_event(): void
    {
        [$tenant, $owner] = $this->preparedClinic();
        $this->bindTenant($tenant);

        $tentative = $this->createBranch($tenant, [
            'location_type' => TenantBranch::LOCATION_EVENT,
            'status' => TenantBranch::STATUS_INACTIVE,
        ]);
        $confirmed = $this->createBranch($tenant, [
            'location_type' => TenantBranch::LOCATION_EVENT,
            'status' => TenantBranch::STATUS_ACTIVE,
        ]);

        $this->assertTrue($tentative->isTentative());
        $this->assertFalse($confirmed->isTentative());

        $patient = $this->patient($tenant, $owner, 'Interested Patient');
        $service = app(EventPreregistrationService::class);

        $registration = $service->register((string) $tentative->getKey(), (string) $patient->getKey(), 'Please notify me', $owner);

        $this->assertSame(EventPreregistration::STATUS_REGISTERED, $registration->status);

        // A confirmed event is a normal booking, never a preregistration.
        try {
            $service->register((string) $confirmed->getKey(), (string) $patient->getKey(), null, $owner);
            $this->fail('Preregistration was accepted for a confirmed event.');
        } catch (PreregistrationException $exception) {
            $this->assertSame('branch_not_tentative', $exception->errorCode());
        }
    }

    public function test_confirming_an_event_promotes_every_registration(): void
    {
        [$tenant, $owner] = $this->preparedClinic();
        $this->bindTenant($tenant);

        $tentative = $this->createBranch($tenant, [
            'location_type' => TenantBranch::LOCATION_EVENT,
            'status' => TenantBranch::STATUS_INACTIVE,
        ]);

        $service = app(EventPreregistrationService::class);

        foreach (['One', 'Two'] as $label) {
            $service->register(
                (string) $tentative->getKey(),
                (string) $this->patient($tenant, $owner, "Interested {$label}")->getKey(),
                null,
                $owner,
            );
        }

        $this->assertSame(2, $service->confirmForBranch((string) $tentative->getKey()));

        $this->assertSame(
            2,
            EventPreregistration::query()
                ->where('branch_id', $tentative->getKey())
                ->where('status', EventPreregistration::STATUS_CONFIRMED)
                ->count(),
        );
    }
}
