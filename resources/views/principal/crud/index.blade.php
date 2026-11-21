@extends('principal.layouts.app', ['title' => __('principal.crud.title')])

@section('content')
    <form method="post" action="{{ route('principal.tailadmin.crud.preview') }}" class="principal-card grid gap-6 p-5 sm:p-6">
        @csrf
        <div class="grid gap-5 sm:grid-cols-2">
            <label class="grid gap-1.5 text-sm font-medium">
                {{ __('principal.crud.model') }}
                <select class="principal-input" name="model">
                    <option value="{{ App\Models\Brand::class }}">{{ __('principal.brands.title') }}</option>
                </select>
            </label>
            <label class="grid gap-1.5 text-sm font-medium">
                {{ __('principal.crud.validation') }}
                <select class="principal-input" name="validation">
                    <option value="brand">{{ __('principal.crud.brand_validation') }}</option>
                </select>
            </label>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="me-1 font-semibold">{{ __('principal.crud.fields') }}</span>
            @foreach (App\Platform\Crud\BrandCrudDefinition::FIELDS as $field)
                <input type="hidden" name="fields[]" value="{{ $field }}">
                <span class="rounded-md bg-slate-100 px-2.5 py-1">{{ __('lba.brands.'.$field) }}</span>
            @endforeach
        </div>
        <div class="grid gap-5 sm:grid-cols-3">
            @foreach ([
                'columns' => App\Platform\Crud\BrandCrudDefinition::FIELDS,
                'filters' => App\Platform\Crud\BrandCrudDefinition::FILTERS,
                'actions' => App\Platform\Crud\BrandCrudDefinition::ACTIONS,
            ] as $group => $items)
                <fieldset class="grid content-start gap-2.5">
                    <legend class="mb-3 text-sm font-semibold">{{ __('principal.crud.'.$group) }}</legend>
                    @php($selected = old($group, session()->hasOldInput() ? [] : $definition[$group]))
                    @foreach ($items as $item)
                        <label class="flex items-center gap-2.5 text-sm">
                            @if ($group === 'columns' && $item === 'name')
                                <input type="hidden" name="columns[]" value="name">
                                <input type="checkbox" class="size-4 accent-teal-700" checked disabled>
                            @else
                                <input type="checkbox" class="size-4 accent-teal-700" name="{{ $group }}[]" value="{{ $item }}"
                                    @checked(in_array($item, (array) $selected, true))>
                            @endif
                            {{ __($group === 'actions' ? 'principal.crud.'.$item : 'lba.brands.'.$item) }}
                        </label>
                    @endforeach
                </fieldset>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-2 border-t border-slate-200 pt-5">
            <button type="submit" class="principal-button principal-button-primary">{{ __('principal.crud.preview') }}</button>
            <button type="submit" class="principal-button" formaction="{{ route('principal.tailadmin.crud.export') }}">{{ __('principal.crud.export') }}</button>
        </div>

        <fieldset class="grid gap-5 border-t border-slate-200 pt-5">
            <legend class="text-sm font-semibold">{{ __('principal.crud.module_legend') }}</legend>
            <p class="text-xs text-slate-500">{{ __('principal.crud.module_hint') }}</p>
            <div class="grid gap-5 sm:grid-cols-3">
                <label class="grid gap-1.5 text-sm font-medium">
                    {{ __('principal.crud.panel') }}
                    <select class="principal-input" name="panel">
                        @foreach ($panels as $panelOption)
                            <option value="{{ $panelOption }}" @selected(old('panel', $panels[0]) === $panelOption)>{{ __('lba.panel.'.$panelOption) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1.5 text-sm font-medium">
                    {{ __('principal.crud.group_key') }}
                    <input class="principal-input" type="text" name="group_key" value="{{ old('group_key', 'lba.generated') }}" maxlength="64">
                </label>
                <label class="grid gap-1.5 text-sm font-medium">
                    {{ __('principal.crud.group_label') }}
                    <input class="principal-input" type="text" name="group_label" value="{{ old('group_label') }}" maxlength="120">
                </label>
                <label class="grid gap-1.5 text-sm font-medium">
                    {{ __('principal.menus.label') }}
                    <input class="principal-input" type="text" name="label" value="{{ old('label') }}" maxlength="120">
                </label>
                <label class="grid gap-1.5 text-sm font-medium">
                    {{ __('principal.menus.route_name') }}
                    <input class="principal-input" type="text" name="route_name" value="{{ old('route_name', 'principal.tailadmin.brands.index') }}" maxlength="160">
                </label>
                <label class="grid gap-1.5 text-sm font-medium">
                    {{ __('principal.crud.permission') }}
                    <select class="principal-input" name="permission_key">
                        <option value="">{{ __('principal.menus.none') }}</option>
                        @foreach ($permissionOptions as $permissionOption)
                            <option value="{{ $permissionOption }}" @selected(old('permission_key') === $permissionOption)>{{ $permissionOption }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <div class="grid gap-2.5">
                <span class="text-sm font-semibold">{{ __('principal.menus.roles') }}</span>
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach ($roleOptions as $roleOption)
                        <label class="flex items-center gap-2.5 text-sm">
                            <input type="checkbox" class="size-4 accent-teal-700" name="role_keys[]" value="{{ $roleOption['key'] }}"
                                @checked(in_array($roleOption['key'], (array) old('role_keys', []), true))>
                            {{ $roleOption['label'] }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="principal-button principal-button-primary" formaction="{{ route('principal.tailadmin.crud.generate') }}">{{ __('principal.crud.generate') }}</button>
            </div>
        </fieldset>
    </form>
    @isset($source)
        <section class="principal-card mt-5 p-5 sm:p-6">
            <h2 class="mb-3 font-semibold text-navy-900">{{ __('principal.crud.generated') }}</h2>
            <pre class="overflow-x-auto rounded-lg bg-navy-900 p-4 text-sm text-slate-100"><code>{{ $source }}</code></pre>
        </section>
    @endisset
@endsection
