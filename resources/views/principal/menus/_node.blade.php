@php
    use App\Platform\Access\RbacCatalog;
    $root = $root ?? false;
@endphp
{{--
    One recursive level of the Menu Dinamis tree.

    A row is the drop target that NESTS the dragged menu (dropping onto it makes
    it a child); the thin gap between rows is the drop target that REORDERS it
    as a sibling. The position the Principal arranges is what `data-menu-save`
    serializes to the server — the server re-checks every id it is given.

    `[data-menu-root]` marks the top-level list of a group; `data-menu-save`
    walks every one of them to build the order payload.
--}}
<ul class="menu-tree grid gap-2" role="list" @if ($root) data-menu-root @endif>
    <li class="menu-tree__gap h-2 rounded bg-slate-100" data-menu-drop aria-hidden="true"></li>
    @foreach ($nodes as $node)
        @php($menu = $node['menu'])
        <li class="menu-tree__item" data-menu-id="{{ $menu->id }}">
            <div class="principal-card grid gap-2 p-3" data-menu-row>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="menu-tree__handle cursor-grab px-1 text-slate-400" data-menu-handle title="{{ __('principal.menus.drag_handle') }}" aria-hidden="true">⠿</span>
                    <span class="font-semibold text-navy-900">{{ $menu->label }}</span>
                    <span class="inline-block rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ $menu->group_label }}</span>
                    @unless ($menu->is_active)
                        <span class="inline-block rounded bg-red-50 px-2 py-0.5 text-xs text-red-700">{{ __('principal.menus.inactive') }}</span>
                    @endunless
                    @if ($menu->permission_key)
                        <span class="inline-block rounded bg-brand-50 px-2 py-0.5 text-xs text-brand-700">{{ $menu->permission_key }}</span>
                    @endif
                    @foreach ($menu->role_keys ?? [] as $roleKey)
                        <span class="inline-block rounded bg-amber-50 px-2 py-0.5 text-xs text-amber-800">{{ RbacCatalog::roleName($roleKey) }}</span>
                    @endforeach
                    @if ($href = $menu->href())
                        <a class="text-xs font-medium text-brand-700 underline" href="{{ $href }}" target="_blank" rel="noopener">{{ __('principal.menus.preview') }}</a>
                    @endif
                </div>

                <div class="flex flex-wrap items-start gap-4">
                    <details class="min-w-0 grow">
                        <summary class="cursor-pointer text-sm font-medium text-brand-700">{{ __('principal.menus.edit') }}</summary>
                        <form method="post" action="{{ route('principal.tailadmin.menus.update', $menu) }}" class="mt-2 grid gap-2 sm:grid-cols-2">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="panel" value="{{ $panel }}">
                            <label class="grid gap-1 text-xs text-slate-600">
                                {{ __('principal.menus.label') }}
                                <input class="principal-input" name="label" value="{{ $menu->label }}" required maxlength="120">
                            </label>
                            <label class="grid gap-1 text-xs text-slate-600">
                                {{ __('principal.menus.icon') }}
                                <input class="principal-input" name="icon" value="{{ $menu->icon }}" maxlength="120">
                            </label>
                            <label class="grid gap-1 text-xs text-slate-600">
                                {{ __('principal.menus.group_key') }}
                                <input class="principal-input" name="group_key" value="{{ $menu->group_key }}" required maxlength="64">
                            </label>
                            <label class="grid gap-1 text-xs text-slate-600">
                                {{ __('principal.menus.group_label') }}
                                <input class="principal-input" name="group_label" value="{{ $menu->group_label }}" required maxlength="120">
                            </label>
                            <label class="grid gap-1 text-xs text-slate-600">
                                {{ __('principal.menus.route_name') }}
                                <input class="principal-input" name="route_name" value="{{ $menu->route_name }}" maxlength="160">
                            </label>
                            <label class="grid gap-1 text-xs text-slate-600">
                                {{ __('principal.menus.url') }}
                                <input class="principal-input" name="url" value="{{ $menu->url }}" maxlength="255">
                            </label>
                            <label class="grid gap-1 text-xs text-slate-600">
                                {{ __('principal.menus.permission') }}
                                <select class="principal-input" name="permission_key">
                                    <option value="">{{ __('principal.menus.none') }}</option>
                                    @foreach ($permissionOptions as $permission)
                                        <option value="{{ $permission }}" @selected($menu->permission_key === $permission)>{{ $permission }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="grid gap-1 text-xs text-slate-600">
                                {{ __('principal.menus.parent') }}
                                <select class="principal-input" name="parent_id">
                                    <option value="">{{ __('principal.menus.parent_root') }}</option>
                                    @foreach ($parentOptions as $option)
                                        @continue ($option['id'] === $menu->id)
                                        <option value="{{ $option['id'] }}" @selected($menu->parent_id === $option['id'])>{{ str_repeat('— ', $option['depth']).$option['label'] }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <fieldset class="grid gap-1 text-xs text-slate-600 sm:col-span-2">
                                <legend>{{ __('principal.menus.roles') }}</legend>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($roleOptions as $role)
                                        <label class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1">
                                            <input type="checkbox" name="role_keys[]" value="{{ $role['key'] }}" @checked(in_array($role['key'], $menu->role_keys ?? [], true))>
                                            <span>{{ $role['label'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <label class="inline-flex items-center gap-2 text-xs text-slate-600">
                                <input type="checkbox" name="is_active" value="1" @checked($menu->is_active)>
                                {{ __('principal.menus.active') }}
                            </label>
                            <div>
                                <button class="principal-button principal-button-primary" type="submit">{{ __('principal.menus.save') }}</button>
                            </div>
                        </form>
                    </details>

                    <form method="post" action="{{ route('principal.tailadmin.menus.destroy', $menu) }}" onsubmit="return confirm('{{ __('principal.menus.delete_confirm') }}')">
                        @csrf
                        @method('DELETE')
                        <button class="principal-button" type="submit">{{ __('principal.menus.delete') }}</button>
                    </form>
                </div>
            </div>

            @if ($node['children'] !== [])
                @include('principal.menus._node', [
                    'nodes' => $node['children'],
                    'panel' => $panel,
                    'parentOptions' => $parentOptions,
                    'roleOptions' => $roleOptions,
                    'permissionOptions' => $permissionOptions,
                    'root' => false,
                ])
            @endif
        </li>
        <li class="menu-tree__gap h-2 rounded bg-slate-100" data-menu-drop aria-hidden="true"></li>
    @endforeach
</ul>
