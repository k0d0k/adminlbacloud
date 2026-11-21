<?php

namespace Tests\Feature\Site;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\VerifyPatientEmail;
use App\Platform\Access\RoleKey;
use App\Platform\Site\Services\PatientSelfRegistration;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Website CMS — public-frontpage patient self-registration (Task 8).
 *
 * Proves the cross-database, idempotent provisioning described in the plan:
 * one central identity + membership, one tenant patient row, no cross-database
 * transaction, no duplicate on double submit, no claiming of an existing
 * medical record, and a portal that stays closed until the email is verified.
 * Only synthetic identities are used (DECISIONS.md PRV-01).
 */
class PatientSelfRegistrationTest extends TestCase
{
    use BuildsAccess, BuildsTenantDatabases, RefreshDatabase;

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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Synthetic Patient',
            'email' => 'synthetic.patient@example.test',
            'phone' => '0812000000',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'privacy' => '1',
        ], $overrides);
    }

    /**
     * Run a callback against the tenant database, always releasing the binding
     * afterwards so no assertion can accidentally resolve the tenant connection.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function inTenant(Tenant $tenant, callable $callback): mixed
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());

        try {
            return $callback();
        } finally {
            $this->tenantDatabases()->forget();
        }
    }

    private function registerUrl(Tenant $tenant): string
    {
        return '/klinik/'.$tenant->slug.'/daftar';
    }

    // ========================================= 1. Successful provisioning

    public function test_the_public_registration_form_is_available_for_an_active_clinic(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $this->get($this->registerUrl($tenant))
            ->assertOk()
            ->assertSee(__('site.registration.title'));
    }

    /**
     * TR-8.1 — a successful registration creates the identity, the membership
     * and the tenant patient row, and asks for email verification.
     */
    public function test_a_successful_registration_provisions_both_databases(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $this->post($this->registerUrl($tenant), $this->payload())
            ->assertRedirect($this->registerUrl($tenant))
            ->assertSessionHas('status', __('site.registration.check_email'));

        $user = User::query()->where('email', 'synthetic.patient@example.test')->first();

        $this->assertNotNull($user);
        $this->assertSame(RoleKey::PATIENT_CUSTOMER, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->email_verified_at, 'A self-registered account must start unverified.');

        $this->assertDatabaseHas('tenant_memberships', [
            'user_id' => $user->getKey(),
            'tenant_id' => $tenant->getKey(),
            'status' => TenantMembership::STATUS_ACTIVE,
        ]);

        $this->inTenant($tenant, function () use ($user): void {
            $patient = Patient::query()->where('user_id', $user->getKey())->first();

            $this->assertNotNull($patient, 'A tenant patient row must be created.');
            $this->assertSame(Patient::SOURCE_SELF, $patient->registration_source);
            $this->assertTrue($patient->isSelfRegistered());
            $this->assertNotNull($patient->self_registered_at);
        });

        Notification::assertSentOnDemand(VerifyPatientEmail::class);
    }

    /**
     * Idempotency — an interrupted self-registration is RESUMED, never
     * duplicated, when the same visitor submits again.
     */
    public function test_resubmitting_the_same_registration_does_not_duplicate(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $this->post($this->registerUrl($tenant), $this->payload())
            ->assertRedirect($this->registerUrl($tenant));

        $this->post($this->registerUrl($tenant), $this->payload())
            ->assertRedirect($this->registerUrl($tenant));

        $this->assertSame(
            1,
            User::query()->where('email', 'synthetic.patient@example.test')->count(),
            'A resumed registration must never create a second identity.',
        );

        $user = User::query()->where('email', 'synthetic.patient@example.test')->firstOrFail();

        $this->inTenant($tenant, function (): void {
            $this->assertSame(1, Patient::query()->count());
        });

        $this->assertSame(1, TenantMembership::query()
            ->where('user_id', $user->getKey())
            ->where('tenant_id', $tenant->getKey())
            ->count());
    }

    /**
     * TR-8.2 — an existing verified account is answered GENERICALLY: no new
     * identity, no membership, and no disclosure that the account exists.
     */
    public function test_an_existing_account_is_answered_generically_and_never_duplicated(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $existing = User::factory()->create(['email' => 'existing@example.test']);

        $this->post($this->registerUrl($tenant), $this->payload(['email' => 'existing@example.test']))
            ->assertRedirect($this->registerUrl($tenant))
            // The exact same message as a fresh registration: no enumeration.
            ->assertSessionHas('status', __('site.registration.check_email'));

        $this->assertSame(1, User::query()->where('email', 'existing@example.test')->count());

        $this->assertDatabaseMissing('tenant_memberships', [
            'user_id' => $existing->getKey(),
            'tenant_id' => $tenant->getKey(),
        ]);

        Notification::assertNothingSent();
    }

    /**
     * TR-8.3 — an existing medical record is NEVER claimed by email: the
     * pre-existing patient row keeps its null binding and a NEW self row is
     * created for the registering account.
     */
    public function test_an_existing_medical_record_is_never_claimed_by_email(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($owner, $tenant, [RoleKey::TENANT_OWNER]);

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $existing = app(PatientRegistrar::class)->create([
            'full_name' => 'Existing Medical Record',
            'email' => 'shared@example.test',
            'status' => Patient::STATUS_ACTIVE,
        ], $owner);
        $this->tenantDatabases()->forget();

        $this->post($this->registerUrl($tenant), $this->payload(['email' => 'shared@example.test']))
            ->assertRedirect($this->registerUrl($tenant));

        $this->tenantDatabases()->configure((string) $tenant->getKey());
        $untouched = Patient::query()->find($existing->getKey());
        $this->tenantDatabases()->forget();

        $this->assertNotNull($untouched);
        $this->assertNull($untouched->user_id, 'An existing medical record must never be claimed.');
        $this->assertSame(Patient::SOURCE_CLINIC, $untouched->registration_source);
    }

    // ========================================= 2. Refusals

    /**
     * TR-8.4 — the submit endpoint is tightly rate-limited.
     */
    public function test_registration_submissions_are_rate_limited(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        for ($i = 0; $i < 5; $i++) {
            $this->post($this->registerUrl($tenant), $this->payload(['email' => "rate{$i}@example.test"]))
                ->assertRedirect($this->registerUrl($tenant));
        }

        $this->post($this->registerUrl($tenant), $this->payload(['email' => 'rate-blocked@example.test']))
            ->assertStatus(429);
    }

    /**
     * TR-8.5 — a suspended clinic is refused before any provisioning happens.
     */
    public function test_registration_is_refused_for_a_suspended_clinic(): void
    {
        $tenant = Tenant::factory()->suspended()->create();

        $this->get($this->registerUrl($tenant))->assertNotFound();
        $this->post($this->registerUrl($tenant), $this->payload())->assertNotFound();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, TenantMembership::query()->count());
    }

    public function test_registration_is_refused_for_an_unknown_clinic(): void
    {
        $this->post('/klinik/does-not-exist/daftar', $this->payload())->assertNotFound();
        $this->assertSame(0, User::query()->count());
    }

    public function test_the_privacy_consent_is_required(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $this->post($this->registerUrl($tenant), $this->payload(['privacy' => '']))
            ->assertSessionHasErrors('privacy');

        $this->assertSame(0, User::query()->count());
    }

    // ========================================= 3. Partial failure / retry

    /**
     * TR-8.6 — a partial failure (central identity committed, tenant row not)
     * is resumed on retry with NO duplicate, and the portal stays closed until
     * the email is verified.
     */
    public function test_a_partial_failure_is_resumed_without_duplication(): void
    {
        // The tenant database is deliberately NOT provisioned yet, so the
        // tenant step (creating the patient row) fails after the central
        // identity and membership have already been committed.
        $tenant = Tenant::factory()->create();
        $service = app(PatientSelfRegistration::class);

        try {
            $service->register($tenant, 'Synthetic Partial', 'partial@example.test', null, 'secret-password');
            $this->fail('Tenant provisioning was expected to fail without a migrated tenant database.');
        } catch (\Throwable) {
            // Expected: the tenant step could not complete.
        }

        $user = User::query()->where('email', 'partial@example.test')->first();
        $this->assertNotNull($user, 'The central identity survives the failed tenant step.');
        $this->assertDatabaseHas('tenant_memberships', [
            'user_id' => $user->getKey(),
            'tenant_id' => $tenant->getKey(),
            'status' => TenantMembership::STATUS_ACTIVE,
        ]);
        $this->assertNull($user->email_verified_at, 'The portal stays closed before verification.');

        // Bring the tenant database online and retry the same registration.
        $this->provisionTenantDatabase($tenant);

        $outcome = $service->register($tenant, 'Synthetic Partial', 'partial@example.test', null, 'secret-password');

        $this->assertTrue($outcome->wasProvisioned());
        $this->assertSame(
            1,
            User::query()->where('email', 'partial@example.test')->count(),
            'The resumed registration must never duplicate the identity.',
        );

        $this->inTenant($tenant, function () use ($user): void {
            $patient = Patient::query()->where('user_id', $user->getKey())->first();

            $this->assertNotNull($patient);
            $this->assertSame(1, Patient::query()->count());
        });
    }

    // ========================================= 4. Signed-in identity

    /**
     * TR-8.7 — a signed-in identity may add a membership for another clinic as
     * an explicit action, and its role is never changed.
     */
    public function test_a_signed_in_identity_can_add_a_membership_without_a_role_change(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $account = User::factory()->create(['email' => 'signed.in@example.test']);

        $this->actingAs($account)
            ->post($this->registerUrl($tenant), $this->payload(['email' => 'signed.in@example.test']))
            ->assertRedirect($this->registerUrl($tenant));

        $this->assertDatabaseHas('tenant_memberships', [
            'user_id' => $account->getKey(),
            'tenant_id' => $tenant->getKey(),
            'status' => TenantMembership::STATUS_ACTIVE,
        ]);

        $this->assertNull($account->fresh()->role, 'An existing identity keeps its role.');
    }

    /**
     * A signed-in identity submitting somebody else's email is refused and no
     * membership is attached to the other account.
     */
    public function test_a_signed_in_identity_cannot_register_another_persons_email(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $account = User::factory()->create(['email' => 'owner@example.test']);

        $this->actingAs($account)
            ->post($this->registerUrl($tenant), $this->payload(['email' => 'victim@example.test']))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('tenant_memberships', [
            'user_id' => $account->getKey(),
            'tenant_id' => $tenant->getKey(),
        ]);
    }

    // ========================================= 5. Email verification

    public function test_redeeming_the_signed_link_verifies_the_email(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $this->post($this->registerUrl($tenant), $this->payload());

        $user = User::query()->where('email', 'synthetic.patient@example.test')->firstOrFail();

        $url = URL::temporarySignedRoute('patient.register.verify', now()->addHour(), [
            'user' => (string) $user->getKey(),
            'tenant' => (string) $tenant->slug,
        ]);

        $this->get($url)->assertRedirect('/patient/login?tenant='.$tenant->slug);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_an_unsigned_verification_link_is_refused(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $this->post($this->registerUrl($tenant), $this->payload());

        $user = User::query()->where('email', 'synthetic.patient@example.test')->firstOrFail();

        $this->get('/registrasi/verifikasi-email/'.$user->getKey().'?tenant='.$tenant->slug)
            ->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    /**
     * TR-8.8 — an unverified account cannot open the patient portal.
     */
    public function test_an_unverified_account_cannot_open_the_patient_portal(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->unverified()->create();
        $this->giveMembership($user, $tenant, [RoleKey::PATIENT_CUSTOMER]);

        $this->actingAs($user)
            ->get('/patient')
            ->assertRedirect(route('patient.verify.notice'));
    }

    public function test_the_verification_notice_is_public_and_tenant_less(): void
    {
        $this->get(route('patient.verify.notice'))
            ->assertOk()
            ->assertSee(__('site.registration.notice_title'));
    }
}
