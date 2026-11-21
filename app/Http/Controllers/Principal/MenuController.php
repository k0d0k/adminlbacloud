<?php

namespace App\Http\Controllers\Principal;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RbacCatalog;
use App\Platform\Access\RoleKey;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Principal "Menu Dinamis" — the per-group navigation builder.
 *
 * The Principal composes the sidebar per panel ("principal" | "clinic") and per
 * group. Nodes nest through `parent_id` (the JS tree lets the Principal drag a
 * menu onto another to make it a child) and keep their arranged position through
 * `sort_order`. Every action re-derives authorization on the server through the
 * same principal authorizer the sidebar uses; the drag-and-drop call never
 * trusts the client beyond the ids it is allowed to reorder.
 */
final class MenuController extends Controller
{
    use AuthorizesPrincipalResources;

    public function index(Request $request): View
    {
        $this->authorizeMenus();

        $panel = $this->panelFrom($request->query('panel'));

        $menus = Menu::query()
            ->forPanel($panel)
            ->orderBy('sort_order')
            ->get();

        return view('principal.menus.index', [
            'panel' => $panel,
            'panels' => Menu::PANELS,
            'groups' => $this->groups($menus),
            'parentOptions' => $this->parentOptions($menus),
            'roleOptions' => $this->roleOptions(),
            'permissionOptions' => $this->permissionOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeMenus();

        $data = $this->validateNode($request);

        Menu::query()->create([
            ...$data,
            'sort_order' => (int) Menu::query()
                ->forPanel($data['panel'])
                ->where('group_key', $data['group_key'])
                ->max('sort_order') + 1,
        ]);

        return $this->backToPanel($data['panel'])->with('success', __('principal.menus.created'));
    }

    public function update(Request $request, Menu $menu): RedirectResponse
    {
        $this->authorizeMenus();

        $data = $this->validateNode($request, $menu);

        $menu->update($data);

        return $this->backToPanel($menu->panel)->with('success', __('principal.menus.updated'));
    }

    public function destroy(Menu $menu): RedirectResponse
    {
        $this->authorizeMenus();

        $panel = $menu->panel;

        // Children are nulled by the schema's `nullOnDelete`, so removing a
        // parent promotes its children to the group root instead of orphaning
        // rows that can never be rendered.
        $menu->delete();

        return $this->backToPanel($panel)->with('success', __('principal.menus.deleted'));
    }

    /**
     * Persist the dragged order and nesting for one panel.
     *
     * The client sends a flat list of `{id, parent_id, sort_order}`; each id is
     * re-checked to belong to the panel (a foreign id is silently discarded), a
     * node can never parent itself, and the whole batch is written in one
     * transaction so a partial drag can never leave a half-arranged menu.
     */
    public function reorder(Request $request): RedirectResponse
    {
        $this->authorizeMenus();

        $data = $request->validate([
            'panel' => ['required', Rule::in(Menu::PANELS)],
            'tree' => ['present', 'array'],
            'tree.*.id' => ['required', 'string', 'max:26'],
            'tree.*.parent_id' => ['nullable', 'string', 'max:26'],
            'tree.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $panel = $data['panel'];

        $owned = Menu::query()->forPanel($panel)->pluck('id')->all();

        DB::transaction(function () use ($data, $panel, $owned): void {
            foreach ($data['tree'] as $node) {
                if (! in_array($node['id'], $owned, true)) {
                    continue;
                }

                $parent = $node['parent_id'] ?? null;

                if ($parent !== null && ($parent === $node['id'] || ! in_array($parent, $owned, true))) {
                    $parent = null;
                }

                Menu::query()
                    ->whereKey($node['id'])
                    ->where('panel', $panel)
                    ->update([
                        'parent_id' => $parent,
                        'sort_order' => (int) $node['sort_order'],
                    ]);
            }
        });

        return $this->backToPanel($panel)->with('success', __('principal.menus.reordered'));
    }

    private function authorizeMenus(): void
    {
        abort_unless(self::principalCan(PermissionKey::PLATFORM_RBAC_MANAGE), 403);
    }

    private function panelFrom(mixed $panel): string
    {
        return is_string($panel) && in_array($panel, Menu::PANELS, true) ? $panel : Menu::PANEL_PRINCIPAL;
    }

    private function backToPanel(string $panel): RedirectResponse
    {
        return redirect()->route('principal.tailadmin.menus.index', ['panel' => $panel]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateNode(Request $request, ?Menu $menu = null): array
    {
        $data = $request->validate([
            'panel' => ['required', Rule::in(Menu::PANELS)],
            'group_key' => ['required', 'string', 'max:64'],
            'group_label' => ['required', 'string', 'max:120'],
            'label' => ['required', 'string', 'max:120'],
            'icon' => ['nullable', 'string', 'max:120'],
            'route_name' => ['nullable', 'string', 'max:160'],
            'url' => ['nullable', 'url', 'max:255'],
            'permission_key' => ['nullable', Rule::in(PermissionKey::ALL)],
            'role_keys' => ['nullable', 'array'],
            'role_keys.*' => ['string', Rule::in(RoleKey::ALL)],
            'parent_id' => [
                'nullable',
                'string',
                'max:26',
                Rule::exists('menus', 'id')->where('panel', $request->input('panel')),
            ],
            'is_active' => ['boolean'],
        ]);

        $data['role_keys'] = array_values($data['role_keys'] ?? []);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['parent_id'] = $this->safeParent($data['parent_id'] ?? null, $menu);

        return $data;
    }

    private function safeParent(?string $parentId, ?Menu $menu): ?string
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        if ($menu !== null && $parentId === $menu->getKey()) {
            return null;
        }

        return $parentId;
    }

    /**
     * The panel's menu grouped by `group_key`, each group holding its nested
     * tree (roots first, children recursive).
     *
     * @param  Collection<int, Menu>  $menus
     * @return list<array{group_key: string, group_label: string, items: list<array<string, mixed>>}>
     */
    private function groups(Collection $menus): array
    {
        $byParent = [];
        $labels = [];

        foreach ($menus as $menu) {
            // Roots are stored under their own group key so `build($groupKey)`
            // can start from the group; children are stored under their parent.
            $byParent[$menu->parent_id ?? $menu->group_key][] = $menu;
            $labels[$menu->group_key] = $menu->group_label;
        }

        $build = function (string $parentKey) use (&$build, $byParent): array {
            $items = [];

            foreach ($byParent[$parentKey] ?? [] as $menu) {
                $items[] = [
                    'menu' => $menu,
                    'children' => $build($menu->id),
                ];
            }

            return $items;
        };

        $groups = [];

        foreach ($menus as $menu) {
            if (array_key_exists($menu->group_key, $groups)) {
                continue;
            }

            $groups[$menu->group_key] = [
                'group_key' => $menu->group_key,
                'group_label' => $labels[$menu->group_key],
                'items' => $build($menu->group_key),
            ];
        }

        return array_values($groups);
    }

    /**
     * Flat, depth-annotated list of the panel's nodes for a parent picker.
     *
     * @param  Collection<int, Menu>  $menus
     * @return list<array{id: string, label: string, depth: int}>
     */
    private function parentOptions(Collection $menus): array
    {
        $children = [];

        foreach ($menus as $menu) {
            $children[$menu->parent_id ?? ''][] = $menu;
        }

        $flat = [];

        $walk = function (string $parentKey, int $depth) use (&$walk, $children, &$flat): void {
            foreach ($children[$parentKey] ?? [] as $menu) {
                $flat[] = ['id' => $menu->id, 'label' => $menu->label, 'depth' => $depth];
                $walk($menu->id, $depth + 1);
            }
        };

        $walk('', 0);

        return $flat;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function roleOptions(): array
    {
        return collect(RoleKey::ALL)
            ->map(fn (string $key): array => ['key' => $key, 'label' => RbacCatalog::roleName($key)])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function permissionOptions(): array
    {
        return PermissionKey::ALL;
    }
}
