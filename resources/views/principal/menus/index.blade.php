@extends('principal.layouts.app', ['title' => __('principal.menus.title')])

@section('content')
    <section class="grid gap-5" data-menu-builder data-menu-panel="{{ $panel }}"
        data-menu-reorder="{{ route('principal.tailadmin.menus.reorder') }}"
        data-menu-error="{{ __('principal.menus.reorder_failed') }}"
        data-menu-token="{{ csrf_token() }}">
        <div class="principal-card grid gap-4 p-4 sm:p-5">
            <div>
                <h1 class="text-lg font-semibold text-navy-900">{{ __('principal.menus.title') }}</h1>
                <p class="text-sm text-slate-600">{{ __('principal.menus.subtitle') }}</p>
            </div>

            <nav class="flex flex-wrap gap-2" aria-label="{{ __('principal.menus.panels') }}">
                @foreach ($panels as $option)
                    <a class="principal-button {{ $option === $panel ? 'principal-button-primary' : '' }}"
                        href="{{ route('principal.tailadmin.menus.index', ['panel' => $option]) }}"
                        @if ($option === $panel) aria-current="page" @endif>{{ __('lba.panel.'.$option) }}</a>
                @endforeach
            </nav>

            <details class="rounded-lg border border-slate-200 p-3">
                <summary class="cursor-pointer text-sm font-semibold text-navy-900">{{ __('principal.menus.add') }}</summary>
                <form method="post" action="{{ route('principal.tailadmin.menus.store') }}" class="mt-3 grid gap-2 sm:grid-cols-2">
                    @csrf
                    <input type="hidden" name="panel" value="{{ $panel }}">
                    <input type="hidden" name="is_active" value="1">
                    <label class="grid gap-1 text-xs text-slate-600">
                        {{ __('principal.menus.label') }}
                        <input class="principal-input" name="label" required maxlength="120" value="{{ old('label') }}">
                    </label>
                    <label class="grid gap-1 text-xs text-slate-600">
                        {{ __('principal.menus.icon') }}
                        <input class="principal-input" name="icon" maxlength="120" value="{{ old('icon') }}">
                    </label>
                    <label class="grid gap-1 text-xs text-slate-600">
                        {{ __('principal.menus.group_key') }}
                        <input class="principal-input" name="group_key" required maxlength="64" value="{{ old('group_key') }}">
                    </label>
                    <label class="grid gap-1 text-xs text-slate-600">
                        {{ __('principal.menus.group_label') }}
                        <input class="principal-input" name="group_label" required maxlength="120" value="{{ old('group_label') }}">
                    </label>
                    <label class="grid gap-1 text-xs text-slate-600">
                        {{ __('principal.menus.route_name') }}
                        <input class="principal-input" name="route_name" maxlength="160" value="{{ old('route_name') }}">
                    </label>
                    <label class="grid gap-1 text-xs text-slate-600">
                        {{ __('principal.menus.url') }}
                        <input class="principal-input" name="url" maxlength="255" value="{{ old('url') }}">
                    </label>
                    <label class="grid gap-1 text-xs text-slate-600">
                        {{ __('principal.menus.permission') }}
                        <select class="principal-input" name="permission_key">
                            <option value="">{{ __('principal.menus.none') }}</option>
                            @foreach ($permissionOptions as $permission)
                                <option value="{{ $permission }}" @selected(old('permission_key') === $permission)>{{ $permission }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-xs text-slate-600">
                        {{ __('principal.menus.parent') }}
                        <select class="principal-input" name="parent_id">
                            <option value="">{{ __('principal.menus.parent_root') }}</option>
                            @foreach ($parentOptions as $option)
                                <option value="{{ $option['id'] }}" @selected(old('parent_id') === $option['id'])>{{ str_repeat('— ', $option['depth']).$option['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <fieldset class="grid gap-1 text-xs text-slate-600 sm:col-span-2">
                        <legend>{{ __('principal.menus.roles') }}</legend>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($roleOptions as $role)
                                <label class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1">
                                    <input type="checkbox" name="role_keys[]" value="{{ $role['key'] }}" @checked(in_array($role['key'], (array) old('role_keys', []), true))>
                                    <span>{{ $role['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <div class="sm:col-span-2">
                        <button class="principal-button principal-button-primary" type="submit">{{ __('principal.menus.save') }}</button>
                    </div>
                </form>
            </details>
        </div>

        <div class="principal-card grid gap-3 p-4 sm:p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-slate-600">{{ __('principal.menus.drag_hint') }}</p>
                <button type="button" class="principal-button principal-button-primary" data-menu-save>{{ __('principal.menus.save_order') }}</button>
            </div>

            @forelse ($groups as $group)
                <div class="grid gap-2">
                    <p class="principal-sidebar__group text-xs font-semibold tracking-wide uppercase">{{ $group['group_label'] }}
                        <span class="text-slate-400">({{ $group['group_key'] }})</span></p>
                    @include('principal.menus._node', [
                        'nodes' => $group['items'],
                        'panel' => $panel,
                        'parentOptions' => $parentOptions,
                        'roleOptions' => $roleOptions,
                        'permissionOptions' => $permissionOptions,
                        'root' => true,
                    ])
                </div>
            @empty
                <p class="p-6 text-center text-sm text-slate-500">{{ __('principal.menus.empty') }}</p>
            @endforelse
        </div>
    </section>
@endsection
