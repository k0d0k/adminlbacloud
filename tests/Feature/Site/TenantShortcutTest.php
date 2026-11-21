<?php

namespace Tests\Feature\Site;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Friendly per-clinic frontpage shortcut: a single-segment path `/{slug}`
 * serves that partner's PUBLIC frontpage (same content as `/klinik/{slug}`),
 * with the staff/patient login CTAs.
 *
 * The shortcut is public — it renders published content only and never opens a
 * session or membership. Authentication still goes through the trusted login
 * flow on the frontpage CTA.
 */
class TenantShortcutTest extends TestCase
{
    use BuildsAccess, BuildsTenantDatabases, RefreshDatabase;

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

    public function test_a_guest_sees_the_clinic_frontpage(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Nubiom']);
        $this->provisionTenantDatabase($tenant);

        $this->get('/'.$tenant->slug)
            ->assertOk()
            ->assertSee($tenant->name);
    }

    public function test_a_signed_in_member_sees_the_public_frontpage(): void
    {
        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        // The shortcut is the PUBLIC frontpage, not the clinic panel — a
        // signed-in member still sees public content and must use the login
        // CTA to enter the panel.
        $this->actingAs($user)
            ->get('/'.$tenant->slug)
            ->assertOk()
            ->assertSee($tenant->name);
    }

    public function test_an_unknown_slug_returns_404(): void
    {
        $this->get('/klinik-yang-tidak-ada')
            ->assertNotFound();
    }

    public function test_a_suspended_tenant_slug_returns_404(): void
    {
        $tenant = Tenant::factory()->suspended()->create();

        $this->get('/'.$tenant->slug)
            ->assertNotFound();
    }

    public function test_reserved_platform_paths_are_not_intercepted(): void
    {
        // /principal must still reach the principal panel (redirect to login),
        // not be treated as a tenant slug.
        $this->get('/principal')
            ->assertRedirect(route('filament.principal.auth.login'));
    }

    public function test_a_multi_segment_path_is_not_a_shortcut(): void
    {
        $this->get('/nubiom/something')
            ->assertNotFound();
    }
}
