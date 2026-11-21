<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\RoleKey;
use App\Platform\Crud\BrandCrudDefinition;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\HeaderUtils;

final class CrudGeneratorController extends Controller
{
    public function index(Request $request, BrandCrudDefinition $catalog): View
    {
        $preview = $request->session()->get('principal.brand_crud_preview');

        return view('principal.crud.index', [
            'definition' => $preview ?? BrandCrudDefinition::defaults(),
            'source' => $preview === null ? null : $catalog->render($preview),
            'panels' => Menu::PANELS,
            'roleOptions' => $this->roleOptions(),
            'permissionOptions' => PermissionKey::ALL,
        ]);
    }

    public function preview(Request $request, BrandCrudDefinition $catalog): RedirectResponse
    {
        return to_route('principal.tailadmin.crud.index')
            ->with('principal.brand_crud_preview', $this->definition($request, $catalog));
    }

    public function export(Request $request, BrandCrudDefinition $catalog): JsonResponse
    {
        return response()->json($this->definition($request, $catalog), options: JSON_PRETTY_PRINT)
            ->header('Content-Disposition', HeaderUtils::makeDisposition('attachment', 'master-brand.json'))
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Requirement 2 — turn a CRUD definition into a live MODULE.
     *
     * The same definition that previews/exports the generated source also
     * composes the module's navigation entry: the generator persists a `menus`
     * row (source `generated`) bound to the selected role(s), so the new module
     * appears in the sidebar for exactly those roles the moment it is created —
     * no separate menu step. The server re-derives menu visibility on every
     * render, so binding a role here never widens access beyond that role.
     */
    public function generate(Request $request, BrandCrudDefinition $catalog): RedirectResponse
    {
        $definition = $this->definition($request, $catalog);

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
        ]);

        $menu = Menu::query()->create([
            'panel' => $data['panel'],
            'group_key' => $data['group_key'],
            'group_label' => $data['group_label'],
            'label' => $data['label'],
            'icon' => $data['icon'] ?? null,
            'route_name' => $data['route_name'] ?? null,
            'url' => $data['url'] ?? null,
            'permission_key' => $data['permission_key'] ?? null,
            'role_keys' => array_values($data['role_keys'] ?? []),
            'sort_order' => (int) Menu::query()
                ->forPanel($data['panel'])
                ->where('group_key', $data['group_key'])
                ->max('sort_order') + 1,
            'is_active' => true,
            'source' => Menu::SOURCE_GENERATED,
        ]);

        return to_route('principal.tailadmin.crud.index')
            ->with('principal.brand_crud_preview', $definition)
            ->with('success', __('principal.crud.generated_module', ['name' => $menu->label]));
    }

    private function definition(Request $request, BrandCrudDefinition $catalog): array
    {
        $definition = $request->only(['model', 'fields', 'validation', 'columns', 'filters', 'actions']);
        $definition += ['filters' => [], 'actions' => []];

        return $catalog->validate($definition);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function roleOptions(): array
    {
        return collect(RoleKey::ALL)
            ->map(fn (string $key): array => ['key' => $key, 'label' => __('lba.roles.'.$key)])
            ->values()
            ->all();
    }
}
