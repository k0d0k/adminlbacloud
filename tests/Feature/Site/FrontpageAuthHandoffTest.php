<?php

namespace Tests\Feature\Site;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsTenantDatabases;
use Tests\Support\SubmitsTheLoginForm;
use Tests\TestCase;

/**
 * Website CMS — sign-in hand-off from a clinic frontpage (Tasks 9 & 10).
 *
 * The frontpage CTAs link to `/patient/login?tenant={slug}` and
 * `/login?tenant={slug}`. No new authentication is introduced: the existing
 * panel guards keep deciding, and the `tenant` query parameter is only a HINT
 * that is re-validated against the identity's own active memberships before it
 * is applied, so it can never grant access on its own.
 *
 * Only synthetic identities and clinics are used (DECISIONS.md PRV-01).
 */
class FrontpageAuthHandoffTest extends TestCase
{
    use BuildsAccess, BuildsTenantDatabases, RefreshDatabase, SubmitsTheLoginForm;

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

    // ------------------------------------------------------------- Patient panel

    /**
     * TR-9.1 — a patient with a single membership signs in from the clinic
     * frontpage and lands inside that clinic's portal.
     */
    public function test_a_patient_signs_in_from_the_clinic_frontpage_and_lands_in_that_clinic(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Klinik Satu']);

        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::PATIENT_CUSTOMER]);

        $this->assertSame(
            '/patient',
            $this->loginRedirect($this->loginThrough('/patient/login?tenant='.$tenant->slug, $user->email)),
        );

        $this->actingAs($user)->get('/patient')->assertOk()->assertSee('Beranda Pasien');
    }

    /**
     * TR-9.2 — a patient identity with no eligible clinic is refused by the
     * existing guard; the parameter never authorizes anything.
     */
    public function test_a_patient_without_a_membership_is_refused_after_signing_in(): void
    {
        $tenant = Tenant::factory()->create();

        // A patient identity with NO active membership anywhere.
        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();

        $this->loginThrough('/patient/login?tenant='.$tenant->slug, $user->email);

        $this->actingAs($user)->get('/patient')->assertForbidden();
    }

    /**
     * TR-9.3 — several eligible memberships and no clinic hint: the identity is
     * asked to choose a clinic rather than being scoped arbitrarily.
     */
    public function test_a_patient_with_several_memberships_is_asked_to_choose(): void
    {
        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($user, Tenant::factory()->create(), [RoleKey::PATIENT_CUSTOMER]);
        $this->giveMembership($user, Tenant::factory()->create(), [RoleKey::PATIENT_CUSTOMER]);

        $this->loginThrough('/patient/login', $user->email);

        $this->actingAs($user)->get('/patient')->assertRedirect('/patient/pilih-klinik');
    }

    /**
     * The clinic named on the login URL is pre-selected — the whole point of the
     * hand-off — so a multi-clinic patient lands directly where they came from.
     */
    public function test_the_clinic_named_on_the_login_url_is_preselected(): void
    {
        $alpha = Tenant::factory()->create(['name' => 'Klinik Alpha', 'slug' => 'klinik-alpha']);
        $beta = Tenant::factory()->create(['name' => 'Klinik Beta', 'slug' => 'klinik-beta']);

        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($user, $alpha, [RoleKey::PATIENT_CUSTOMER]);
        $this->giveMembership($user, $beta, [RoleKey::PATIENT_CUSTOMER]);

        $this->loginThrough('/patient/login?tenant='.$beta->slug, $user->email);

        $this->actingAs($user)->get('/patient')->assertOk()->assertSee('Beranda Pasien');
    }

    /**
     * A hint for a clinic the identity may NOT enter is ignored and consumed:
     * nothing is selected, so the ordinary single-membership auto-select applies.
     */
    public function test_a_hint_for_a_foreign_clinic_is_ignored(): void
    {
        $home = Tenant::factory()->create(['name' => 'Klinik Rumah']);
        $foreign = Tenant::factory()->create(['name' => 'Klinik Asing']);

        $user = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($user, $home, [RoleKey::PATIENT_CUSTOMER]);

        $this->loginThrough('/patient/login?tenant='.$foreign->slug, $user->email);

        // Falls through to the only eligible clinic (Home), never the foreign one.
        $this->actingAs($user)->get('/patient')->assertOk()->assertSee('Beranda Pasien');
    }

    // -------------------------------------------------------------- Clinic panel

    /**
     * TR-10.1 — a staff identity signs in from the clinic frontpage and reaches
     * the clinic panel (the tenant guard then scopes it to its membership).
     */
    public function test_a_staff_member_signs_in_from_the_clinic_frontpage(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        $this->assertSame(
            '/clinic',
            $this->loginRedirect($this->loginThrough('/login?tenant='.$tenant->slug, $user->email)),
        );
    }

    /**
     * TR-10.2 — a staff identity with no eligible clinic is refused by the
     * existing clinic guard.
     */
    public function test_a_staff_member_without_a_membership_is_refused(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();

        $this->loginThrough('/login?tenant='.$tenant->slug, $user->email);

        $this->actingAs($user)->get('/clinic/workspace')->assertForbidden();
    }

    /**
     * TR-10.3 — the Principal identity is unaffected by the clinic hand-off: it
     * still resolves to the Principal panel (and its MFA flow is enforced by the
     * existing Principal authentication tests).
     */
    public function test_the_principal_identity_is_unaffected_by_the_clinic_hint(): void
    {
        $tenant = Tenant::factory()->create();

        $principal = User::factory()->role(RoleKey::PRINCIPAL_SUPER_ADMIN)->create();
        $this->grantPrincipalRole($principal, RoleKey::PRINCIPAL_SUPER_ADMIN);

        $this->assertSame(
            '/principal',
            $this->loginRedirect($this->loginThrough('/login?tenant='.$tenant->slug, $principal->email)),
        );
    }
}
