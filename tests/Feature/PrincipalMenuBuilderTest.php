<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\TestCase;

/**
 * Menu Dinamis — the per-group navigation builder.
 *
 * Covers the composition surface end to end: creating grouped and nested
 * entries, persisting the dragged order/nesting, re-deriving visibility per
 * actor (fail-closed) and refusing a principal who lacks the access-management
 * capability.
 */
class PrincipalMenuBuilderTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_composes_grouped_nested_entries_that_render_in_the_sidebar(): void
    {
        $actor = $this->principal();

        $this->actingAs($actor)
            ->post(route('principal.tailadmin.menus.store'), [
                'panel' => Menu::PANEL_PRINCIPAL,
                'group_key' => 'lba.extra',
                'group_label' => 'Grup Tambahan',
                'label' => 'Laporan Khusus',
                'route_name' => 'principal.tailadmin.analytics.index',
                'is_active' => '1',
            ])
            ->assertRedirect(route('principal.tailadmin.menus.index', ['panel' => Menu::PANEL_PRINCIPAL]));

        $parent = Menu::query()->where('label', 'Laporan Khusus')->sole();
        $this->assertSame('lba.extra', $parent->group_key);
        $this->assertTrue($parent->is_active);

        $this->actingAs($actor)
            ->post(route('principal.tailadmin.menus.store'), [
                'panel' => Menu::PANEL_PRINCIPAL,
                'group_key' => 'lba.extra',
                'group_label' => 'Grup Tambahan',
                'label' => 'Sub Laporan',
                'url' => 'https://example.test/sub',
                'parent_id' => $parent->getKey(),
                'is_active' => '1',
            ])
            ->assertRedirect();

        $child = Menu::query()->where('label', 'Sub Laporan')->sole();
        $this->assertSame($parent->getKey(), $child->parent_id);

        // The composed entry (and its group) renders on a normal principal page
        // through the shared sidebar.
        $this->actingAs($actor)
            ->get(route('principal.tailadmin.brands.index'))
            ->assertOk()
            ->assertSee('Grup Tambahan')
            ->assertSee('Laporan Khusus')
            ->assertSee('Sub Laporan')
            ->assertSee('https://example.test/sub');
    }

    public function test_reorder_persists_the_dragged_order_and_nesting(): void
    {
        $actor = $this->principal();

        $first = Menu::query()->create([
            'panel' => Menu::PANEL_PRINCIPAL,
            'group_key' => 'lba.extra',
            'group_label' => 'Grup Tambahan',
            'label' => 'Satu',
            'sort_order' => 0,
        ]);
        $second = Menu::query()->create([
            'panel' => Menu::PANEL_PRINCIPAL,
            'group_key' => 'lba.extra',
            'group_label' => 'Grup Tambahan',
            'label' => 'Dua',
            'sort_order' => 1,
        ]);
        $foreign = Menu::query()->create([
            'panel' => Menu::PANEL_CLINIC,
            'group_key' => 'lba.clinic',
            'group_label' => 'Klinik',
            'label' => 'Asing',
            'sort_order' => 0,
        ]);

        $this->actingAs($actor)
            ->post(route('principal.tailadmin.menus.reorder'), [
                'panel' => Menu::PANEL_PRINCIPAL,
                'tree' => [
                    // Nest the first under the second, and give the second the
                    // later slot; a foreign-panel id must be ignored.
                    ['id' => $first->getKey(), 'parent_id' => $second->getKey(), 'sort_order' => 0],
                    ['id' => $second->getKey(), 'parent_id' => null, 'sort_order' => 7],
                    ['id' => $foreign->getKey(), 'parent_id' => null, 'sort_order' => 99],
                ],
            ])
            ->assertRedirect(route('principal.tailadmin.menus.index', ['panel' => Menu::PANEL_PRINCIPAL]));

        $this->assertSame($second->getKey(), $first->fresh()->parent_id);
        $this->assertSame(7, $second->fresh()->sort_order);

        // The clinic-panel node is untouched by a principal-panel reorder.
        $this->assertSame(0, $foreign->fresh()->sort_order);
    }

    public function test_a_dynamic_entry_is_hidden_when_the_actor_lacks_its_role(): void
    {
        $actor = $this->principal();

        Menu::query()->create([
            'panel' => Menu::PANEL_PRINCIPAL,
            'group_key' => 'lba.gated',
            'group_label' => 'Grup Terbatas',
            'label' => 'Hanya Mitra',
            'url' => 'https://example.test/tenant-only',
            // The super admin does NOT hold this tenant role, so the entry must
            // stay out of their sidebar while still being editable in the builder.
            'role_keys' => [RoleKey::TENANT_OWNER],
        ]);

        $this->actingAs($actor)
            ->get(route('principal.tailadmin.brands.index'))
            ->assertOk()
            ->assertDontSee('Hanya Mitra');

        $this->actingAs($actor)
            ->get(route('principal.tailadmin.menus.index'))
            ->assertOk()
            ->assertSee('Hanya Mitra');
    }

    public function test_menu_builder_requires_the_access_management_capability(): void
    {
        $finance = $this->principal(RoleKey::PRINCIPAL_FINANCE);
        $this->assertFalse(app(\App\Platform\Access\Contracts\AuthorizesPrincipal::class)->can($finance, PermissionKey::PLATFORM_RBAC_MANAGE));

        $this->actingAs($finance)
            ->get(route('principal.tailadmin.menus.index'))
            ->assertForbidden();

        $this->actingAs($finance)
            ->post(route('principal.tailadmin.menus.store'), [
                'panel' => Menu::PANEL_PRINCIPAL,
                'group_key' => 'lba.extra',
                'group_label' => 'Grup Tambahan',
                'label' => 'Nope',
            ])
            ->assertForbidden();

        $this->assertSame(0, Menu::query()->count());
    }
}
