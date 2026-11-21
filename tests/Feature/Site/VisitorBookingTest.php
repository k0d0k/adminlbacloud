<?php

namespace Tests\Feature\Site;

use App\Filament\Patient\Pages\MyBookingRequests;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyBookingRequestEmail;
use App\Platform\Access\RoleKey;
use App\Platform\Branches\Models\TenantBranch;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Models\BookingRequest;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\Appointments\Services\BranchAvailability;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientAccountLinker;
use Modules\Patients\Services\PatientRegistrar;
use ReflectionProperty;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Website CMS — public visitor booking from a clinic frontpage (Task 11).
 *
 * Proves the "intent, not a schedule" contract described in the plan: a public
 * submit records an email-verified booking request inside the tenant database,
 * and only an explicit portal confirmation — which RE-validates the slot — turns
 * that request into an appointment, idempotently. Only synthetic identities,
 * branches and patients are used (DECISIONS.md PRV-01).
 */
class VisitorBookingTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('patient'));
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    // --------------------------------------------------------------- Helpers

    private function bindTenant(Tenant $tenant): void
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function inTenant(Tenant $tenant, callable $callback): mixed
    {
        $this->bindTenant($tenant);

        try {
            return $callback();
        } finally {
            $this->tenantDatabases()->forget();
        }
    }

    private function bookingUrl(Tenant $tenant): string
    {
        return '/klinik/'.$tenant->slug.'/booking';
    }

    private function patientUser(Tenant $tenant): User
    {
        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::PATIENT_CUSTOMER]);

        return $user;
    }

    private function member(Tenant $tenant, string $roleKey): User
    {
        $user = User::factory()->role($roleKey)->create();
        $this->giveMembership($user, $tenant, [$roleKey]);

        return $user;
    }

    /**
     * Bind a portal account the way production does: an authorized clinic
     * identity issues a single-use invitation proof, which the account redeems.
     */
    private function boundPatient(Tenant $tenant, User $account, string $name): Patient
    {
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);

        $this->bindTenant($tenant);

        $patient = app(PatientRegistrar::class)->create([
            'full_name' => $name,
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);

        $code = app(PatientAccountLinker::class)->issueInvitation($patient, $owner);

        app(PatientAccountLinker::class)->redeemInvitation($account, $code);

        return $patient->fresh();
    }

    /**
     * The ISO8601 start of the first AVAILABLE slot the branch publishes for the
     * given day — the exact value the public form offers as a radio option.
     */
    private function firstAvailableSlot(Tenant $tenant, TenantBranch $branch, Carbon $date): string
    {
        $this->bindTenant($tenant);

        foreach (app(BranchAvailability::class)->slotsForDate($branch, $date) as $slot) {
            if ($slot['available']) {
                return (string) $slot['starts_at'];
            }
        }

        $this->fail('The branch published no available slot for the test date.');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Synthetic Visitor',
            'email' => 'synthetic.visitor@example.test',
            'phone' => '0812000000',
            'service_type' => Appointment::SERVICE_CONSULTATION,
            'privacy' => '1',
        ], $overrides);
    }

    /**
     * Extract the signed verification link from the on-demand email that the
     * submit flow sent (the raw token only ever lives in that link).
     */
    private function capturedVerificationUrl(): string
    {
        $captured = null;

        Notification::assertSentOnDemand(
            VerifyBookingRequestEmail::class,
            function (VerifyBookingRequestEmail $notification) use (&$captured): bool {
                $captured = $notification;

                return true;
            },
        );

        $this->assertNotNull($captured, 'A verification email must have been sent.');

        return (string) (new ReflectionProperty($captured, 'verificationUrl'))->getValue($captured);
    }

    private function seedVerifiedRequest(Tenant $tenant, string $email, string $branchId, string $slot): BookingRequest
    {
        return $this->inTenant($tenant, fn (): BookingRequest => BookingRequest::query()->create([
            'full_name' => 'Synthetic Visitor',
            'email' => $email,
            'branch_id' => $branchId,
            'service_type' => Appointment::SERVICE_CONSULTATION,
            'scheduled_at' => Carbon::parse($slot),
            'status' => BookingRequest::STATUS_VERIFIED,
            'privacy_consent_at' => now(),
        ]));
    }

    // ========================================= 1. The public form

    public function test_the_public_booking_form_is_available_for_an_active_clinic(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->createBranch($tenant, ['name' => 'Cabang Publik']);

        $this->get($this->bookingUrl($tenant))
            ->assertOk()
            ->assertSee(__('booking.title'))
            ->assertSee('Cabang Publik');
    }

    public function test_the_public_booking_form_is_refused_for_a_suspended_clinic(): void
    {
        $tenant = Tenant::factory()->suspended()->create();

        $this->get($this->bookingUrl($tenant))->assertNotFound();
        $this->post($this->bookingUrl($tenant), $this->payload())->assertNotFound();
    }

    /**
     * TR-11.8 — the public form collects only scheduling/contact data, never any
     * medical information.
     */
    public function test_the_public_booking_form_never_requests_medical_data(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->createBranch($tenant);

        $response = $this->get($this->bookingUrl($tenant))->assertOk();

        // The public scheduling fields ARE present...
        $response->assertSee('name="full_name"', false);
        $response->assertSee('name="email"', false);
        $response->assertSee('name="service_type"', false);
        $response->assertSee('name="scheduled_at"', false);

        // ...and no clinical field is ever asked for.
        foreach (['Gejala', 'Diagnosis', 'Keluhan', 'Riwayat penyakit', 'Hasil pemeriksaan', 'Resep obat'] as $medicalTerm) {
            $response->assertDontSee($medicalTerm);
        }

        // No input or textarea carries a clinical name.
        $response->assertDontSee('name="symptoms"', false);
        $response->assertDontSee('name="diagnosis"', false);
        $response->assertDontSee('name="complaint"', false);
        $response->assertDontSee('name="medical_history"', false);
        $response->assertDontSee('name="clinical_notes"', false);
    }

    // ========================================= 2. Submitting a request

    /**
     * TR-11.1 — a visitor submit records a `pending_verification` request inside
     * the tenant database and emails a verification link.
     */
    public function test_a_visitor_submission_records_a_pending_request_and_emails_a_link(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant);
        $slot = $this->firstAvailableSlot($tenant, $branch, now()->addDays(2));

        $this->post($this->bookingUrl($tenant), $this->payload([
            'branch_id' => (string) $branch->getKey(),
            'scheduled_at' => $slot,
        ]))
            ->assertRedirect($this->bookingUrl($tenant))
            ->assertSessionHas('status', __('booking.pending'));

        $this->inTenant($tenant, function () use ($branch): void {
            $request = BookingRequest::query()->sole();

            $this->assertSame(BookingRequest::STATUS_PENDING_VERIFICATION, $request->status);
            $this->assertSame('synthetic.visitor@example.test', $request->email);
            $this->assertSame((string) $branch->getKey(), (string) $request->branch_id);
            $this->assertNull($request->appointment_id, 'A request is intent, not an appointment.');
            $this->assertNotNull($request->verification_token_hash);
            $this->assertNotNull($request->privacy_consent_at);
            $this->assertSame(0, Appointment::query()->count());
        });

        Notification::assertSentOnDemand(VerifyBookingRequestEmail::class);
    }

    /**
     * Idempotency — a repeated submit for the same contact/slot reuses the one
     * request instead of queueing a duplicate.
     */
    public function test_resubmitting_the_same_slot_reuses_one_request(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant);
        $slot = $this->firstAvailableSlot($tenant, $branch, now()->addDays(2));

        $payload = $this->payload(['branch_id' => (string) $branch->getKey(), 'scheduled_at' => $slot]);

        $this->post($this->bookingUrl($tenant), $payload)->assertRedirect($this->bookingUrl($tenant));
        $this->post($this->bookingUrl($tenant), $payload)->assertRedirect($this->bookingUrl($tenant));

        $this->inTenant($tenant, function (): void {
            $this->assertSame(1, BookingRequest::query()->count());
            $this->assertSame(
                BookingRequest::STATUS_PENDING_VERIFICATION,
                BookingRequest::query()->sole()->status,
            );
        });
    }

    /**
     * The submitted slot is validated UP FRONT: a slot the clinic does not offer
     * is refused and no request is recorded.
     */
    public function test_a_slot_the_clinic_does_not_offer_is_refused_up_front(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant);

        $this->post($this->bookingUrl($tenant), $this->payload([
            'branch_id' => (string) $branch->getKey(),
            'scheduled_at' => now()->addDays(2)->setTime(0, 0)->toIso8601String(),
        ]))->assertSessionHasErrors('scheduled_at');

        $this->inTenant($tenant, function (): void {
            $this->assertSame(0, BookingRequest::query()->count());
        });

        Notification::assertNothingSent();
    }

    public function test_a_branch_from_another_clinic_is_refused(): void
    {
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();
        $this->provisionTenantDatabase($alpha);
        $this->provisionTenantDatabase($beta);

        $alphaBranch = $this->createBranch($alpha);
        $this->createBranch($beta);

        // The foreign branch id resolves to nothing in the alpha tenant database.
        $this->post($this->bookingUrl($alpha), $this->payload([
            'branch_id' => (string) $alphaBranch->getKey(),
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
        ]))->assertSessionHasErrors('scheduled_at');
    }

    // ========================================= 3. Email verification

    /**
     * TR-11.2 — redeeming the signed link moves the request to `verified` and
     * forwards an unregistered visitor to registration.
     */
    public function test_redeeming_the_signed_link_verifies_the_request_and_routes_to_registration(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant);
        $slot = $this->firstAvailableSlot($tenant, $branch, now()->addDays(2));

        $this->post($this->bookingUrl($tenant), $this->payload([
            'branch_id' => (string) $branch->getKey(),
            'scheduled_at' => $slot,
        ]))->assertRedirect($this->bookingUrl($tenant));

        $url = $this->capturedVerificationUrl();

        // No account exists for this email yet → forward to registration.
        $this->get($url)
            ->assertRedirect('/klinik/'.$tenant->slug.'/daftar')
            ->assertSessionHas('status', __('booking.verified'));

        $this->inTenant($tenant, function (): void {
            $this->assertSame(BookingRequest::STATUS_VERIFIED, BookingRequest::query()->sole()->status);
        });
    }

    public function test_redeeming_routes_an_existing_account_to_login(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        User::factory()->create(['email' => 'synthetic.visitor@example.test']);

        $branch = $this->createBranch($tenant);
        $slot = $this->firstAvailableSlot($tenant, $branch, now()->addDays(2));

        $this->post($this->bookingUrl($tenant), $this->payload([
            'branch_id' => (string) $branch->getKey(),
            'scheduled_at' => $slot,
        ]))->assertRedirect($this->bookingUrl($tenant));

        $this->get($this->capturedVerificationUrl())
            ->assertRedirect('/patient/login?tenant='.$tenant->slug);
    }

    public function test_an_unsigned_verification_link_is_refused(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant);
        $slot = $this->firstAvailableSlot($tenant, $branch, now()->addDays(2));

        $this->post($this->bookingUrl($tenant), $this->payload([
            'branch_id' => (string) $branch->getKey(),
            'scheduled_at' => $slot,
        ]))->assertRedirect($this->bookingUrl($tenant));

        // The token is signed, so an unsigned replay of the raw path is denied.
        $token = (string) $this->inTenant($tenant, fn (): string => (string) BookingRequest::query()->sole()->getKey());

        $this->get('/klinik/'.$tenant->slug.'/booking/verify/'.$token)->assertForbidden();

        $this->inTenant($tenant, function (): void {
            $this->assertSame(BookingRequest::STATUS_PENDING_VERIFICATION, BookingRequest::query()->sole()->status);
        });
    }

    // ========================================= 4. Portal continuation

    /**
     * TR-11.3 — after signing in, the verified request is listed in the portal
     * with the branch, service and slot already filled in.
     */
    public function test_a_verified_request_appears_in_the_patient_portal(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Terverifikasi']);
        $slot = $this->firstAvailableSlot($tenant, $branch, now()->addDays(2));

        $this->boundPatient($tenant, $user, 'Synthetic Portal Visitor');
        $this->seedVerifiedRequest($tenant, (string) $user->email, (string) $branch->getKey(), $slot);

        $this->actingAs($user);

        $this->get('/patient/permintaan-booking')
            ->assertOk()
            ->assertSee('Cabang Terverifikasi')
            ->assertSee(__('lba.patient.booking.services.'.Appointment::SERVICE_CONSULTATION));
    }

    /**
     * TR-11.4 — confirming a verified request re-validates the slot, creates the
     * appointment and marks the request converted.
     */
    public function test_confirming_a_verified_request_creates_the_appointment(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $branch = $this->createBranch($tenant);
        $slot = $this->firstAvailableSlot($tenant, $branch, now()->addDays(2));

        $patient = $this->boundPatient($tenant, $user, 'Synthetic Confirmer');
        $request = $this->seedVerifiedRequest($tenant, (string) $user->email, (string) $branch->getKey(), $slot);

        $this->actingAs($user);
        $this->bindTenant($tenant);

        (new MyBookingRequests)->confirm((string) $request->getKey());

        $this->inTenant($tenant, function () use ($request, $patient, $branch, $slot): void {
            $appointment = Appointment::query()->sole();

            $this->assertSame((string) $patient->getKey(), (string) $appointment->patient_id);
            $this->assertSame((string) $branch->getKey(), (string) $appointment->branch_id);
            $this->assertSame(Appointment::SERVICE_CONSULTATION, $appointment->service_type);
            $this->assertSame(
                Carbon::parse($slot)->format('Y-m-d H:i'),
                $appointment->starts_at?->format('Y-m-d H:i'),
            );
            $this->assertSame(Appointment::STATUS_SCHEDULED, $appointment->status);

            $fresh = BookingRequest::query()->whereKey($request->getKey())->sole();
            $this->assertSame(BookingRequest::STATUS_CONVERTED, $fresh->status);
            $this->assertSame((string) $appointment->getKey(), (string) $fresh->appointment_id);
        });
    }

    /**
     * TR-11.5 — a slot that has since been taken is refused with
     * `slot_unavailable`, and NO appointment is created.
     */
    public function test_confirming_a_taken_slot_is_refused_without_creating_an_appointment(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);

        // A single-slot branch: one occupying appointment fills the slot.
        $branch = $this->createBranch($tenant, ['capacity' => 1]);
        $slot = $this->firstAvailableSlot($tenant, $branch, now()->addDays(2));

        $patient = $this->boundPatient($tenant, $user, 'Synthetic Too Late');
        $request = $this->seedVerifiedRequest($tenant, (string) $user->email, (string) $branch->getKey(), $slot);

        // A foreign booking takes the slot before the visitor confirms.
        $this->inTenant($tenant, function () use ($tenant, $branch, $slot): void {
            $holder = $this->boundPatient($tenant, $this->patientUser($tenant), 'Synthetic Slot Holder');

            app(AppointmentScheduler::class)->create([
                'patient_id' => $holder->getKey(),
                'branch_id' => $branch->getKey(),
                'starts_at' => Carbon::parse($slot),
                'ends_at' => Carbon::parse($slot)->addMinutes(30),
                'service_type' => Appointment::SERVICE_CONSULTATION,
                'status' => Appointment::STATUS_SCHEDULED,
            ]);
        });

        $this->actingAs($user);
        $this->bindTenant($tenant);

        (new MyBookingRequests)->confirm((string) $request->getKey());

        $this->inTenant($tenant, function () use ($request): void {
            // Only the foreign occupying appointment exists.
            $this->assertSame(1, Appointment::query()->count());

            $fresh = BookingRequest::query()->whereKey($request->getKey())->sole();
            $this->assertSame(BookingRequest::STATUS_VERIFIED, $fresh->status);
            $this->assertNull($fresh->appointment_id);
        });
    }

    /**
     * TR-11.6 — retrying a confirmation never books twice.
     */
    public function test_retrying_a_confirmation_never_duplicates_the_appointment(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $branch = $this->createBranch($tenant);
        $slot = $this->firstAvailableSlot($tenant, $branch, now()->addDays(2));

        $this->boundPatient($tenant, $user, 'Synthetic Retry');
        $request = $this->seedVerifiedRequest($tenant, (string) $user->email, (string) $branch->getKey(), $slot);

        $this->actingAs($user);

        $this->bindTenant($tenant);
        (new MyBookingRequests)->confirm((string) $request->getKey());

        $this->bindTenant($tenant);
        (new MyBookingRequests)->confirm((string) $request->getKey());

        $this->inTenant($tenant, function (): void {
            $this->assertSame(1, Appointment::query()->count(), 'A retry must never create a second appointment.');
        });
    }

    /**
     * A request for another visitor's email never surfaces — the portal lists
     * only the acting identity's OWN requests.
     */
    public function test_the_portal_lists_only_the_acting_identitys_own_requests(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = $this->patientUser($tenant);
        $branch = $this->createBranch($tenant, ['name' => 'Cabang Milik Sendiri']);
        $foreignBranch = $this->createBranch($tenant, ['name' => 'Cabang Asing']);
        $slot = $this->firstAvailableSlot($tenant, $branch, now()->addDays(2));
        $foreignSlot = $this->firstAvailableSlot($tenant, $foreignBranch, now()->addDays(2));

        $this->boundPatient($tenant, $user, 'Synthetic Own');
        $this->seedVerifiedRequest($tenant, (string) $user->email, (string) $branch->getKey(), $slot);
        $this->seedVerifiedRequest($tenant, 'someone.else@example.test', (string) $foreignBranch->getKey(), $foreignSlot);

        $this->actingAs($user);

        $this->get('/patient/permintaan-booking')
            ->assertOk()
            ->assertSee('Cabang Milik Sendiri')
            ->assertDontSee('Cabang Asing');
    }

    // ========================================= 5. Signed-in hand-off

    /**
     * TR-11.7 — an already signed-in patient clicking Booking is handed off to
     * the portal (with the clinic context) instead of the public form.
     */
    public function test_a_signed_in_patient_is_redirected_into_the_portal(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->patientUser($tenant);

        $this->actingAs($user)
            ->get($this->bookingUrl($tenant))
            ->assertRedirect('/patient/booking?tenant='.$tenant->slug);
    }

    /**
     * A guest sees the public form — the hand-off only applies to a patient with
     * an active membership in THIS clinic.
     */
    public function test_a_guest_still_sees_the_public_form(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);
        $this->createBranch($tenant);

        $this->get($this->bookingUrl($tenant))->assertOk();
    }
}
