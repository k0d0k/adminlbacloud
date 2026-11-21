@extends('principal.layouts.app', ['title' => __('lba.principal.sina.title')])

@section('content')
    <div class="grid gap-5">
        <p class="text-sm text-slate-600">{{ __('lba.principal.sina.subtitle') }}</p>

        {{-- 1. Token & price — the "price of one chat". --}}
        <section class="principal-card grid gap-4 p-5">
            <div>
                <h2 class="font-semibold text-navy-900">{{ __('lba.principal.sina.billing_title') }}</h2>
                <p class="text-xs text-slate-500">{{ __('lba.principal.sina.billing_description') }}</p>
            </div>

            @if ($canManage)
                <form method="post" action="{{ route('principal.tailadmin.sina.billing.update') }}" class="grid gap-4">
                    @csrf
                    @method('patch')
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="enabled" value="1" @checked($billing['enabled'])>
                        {{ __('lba.principal.sina.billing_enabled') }}
                    </label>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.principal.sina.credits_per_turn') }}
                            <input class="principal-input" type="number" min="0" name="credits_per_turn"
                                value="{{ old('credits_per_turn', $billing['credits_per_turn']) }}" required>
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.principal.sina.credits_per_1k_tokens') }}
                            <input class="principal-input" type="number" min="0" name="credits_per_1k_tokens"
                                value="{{ old('credits_per_1k_tokens', $billing['credits_per_1k_tokens']) }}" required>
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.principal.sina.currency') }}
                            <input class="principal-input" name="currency" maxlength="32"
                                value="{{ old('currency', $billing['currency']) }}" required>
                        </label>
                    </div>
                    <p class="text-xs text-slate-500">{{ __('lba.principal.sina.credits_per_turn_help') }}</p>
                    <button class="principal-button principal-button-primary justify-self-start">{{ __('lba.principal.sina.billing_save') }}</button>
                </form>
            @else
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-4">
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('lba.principal.sina.billing_enabled') }}</dt>
                        <dd class="font-medium">{{ __($billing['enabled'] ? 'lba.principal.sina.status_enabled' : 'lba.principal.sina.status_disabled') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('lba.principal.sina.credits_per_turn') }}</dt>
                        <dd class="font-medium">{{ $billing['credits_per_turn'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('lba.principal.sina.credits_per_1k_tokens') }}</dt>
                        <dd class="font-medium">{{ $billing['credits_per_1k_tokens'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('lba.principal.sina.currency') }}</dt>
                        <dd class="font-medium">{{ $billing['currency'] }}</dd>
                    </div>
                </dl>
            @endif
        </section>

        {{-- 2. Provider endpoint per partner. --}}
        <section class="principal-card grid gap-4 p-5">
            <div>
                <h2 class="font-semibold text-navy-900">{{ __('lba.principal.sina.provider_title') }}</h2>
                <p class="text-xs text-slate-500">{{ __('lba.principal.sina.provider_description') }}</p>
            </div>

            @if ($canManage)
                @error('tenant_id')
                    <p class="text-sm text-rose-600">{{ $message }}</p>
                @enderror
                @error('base_url')
                    <p class="text-sm text-rose-600">{{ $message }}</p>
                @enderror
                <form method="post" action="{{ route('principal.tailadmin.sina.providers.save') }}" class="grid gap-4">
                    @csrf
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.principal.sina.provider_tenant') }}
                            <select class="principal-input" name="tenant_id" required>
                                <option value="">{{ __('lba.principal.ai_providers.select_placeholder') }}</option>
                                @foreach ($tenants as $tenant)
                                    <option value="{{ $tenant->getKey() }}" @selected(old('tenant_id') === (string) $tenant->getKey())>{{ $tenant->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.principal.sina.provider_type') }}
                            <select class="principal-input" name="provider" required>
                                @foreach ($providerOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('provider') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.principal.sina.provider_base_url') }}
                            <input class="principal-input" name="base_url" maxlength="255"
                                value="{{ old('base_url') }}" placeholder="http://minimac.local:3000/api" required>
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.principal.sina.provider_model') }}
                            <input class="principal-input" name="model" maxlength="190"
                                value="{{ old('model') }}" placeholder="qwen2.5:7b" required>
                        </label>
                        <label class="grid gap-1 text-sm sm:col-span-2">
                            {{ __('lba.principal.sina.provider_api_key') }}
                            <input class="principal-input" name="api_key" maxlength="1000" autocomplete="off"
                                placeholder="{{ __('lba.principal.ai_providers.api_key_placeholder') }}">
                        </label>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="enabled" value="1" checked>
                        {{ __('lba.principal.sina.provider_enabled') }}
                    </label>
                    <button class="principal-button principal-button-primary justify-self-start">{{ __('lba.principal.ai_providers.save') }}</button>
                </form>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            @foreach (['provider_tenant', 'provider_type', 'provider_base_url', 'provider_model', 'status'] as $column)
                                <th scope="col" class="px-4 py-3 text-start">{{ __('lba.principal.sina.'.$column) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($providers as $provider)
                            <tr>
                                <td class="px-4 py-3 font-semibold">{{ $provider->tenant?->name ?? $provider->tenant_id }}</td>
                                <td class="px-4 py-3">{{ $providerOptions[$provider->provider] ?? $provider->provider }}</td>
                                <td class="wrap-break-word px-4 py-3">{{ $provider->base_url }}</td>
                                <td class="px-4 py-3">{{ $provider->model }}</td>
                                <td class="px-4 py-3">{{ __($provider->enabled ? 'lba.principal.sina.status_enabled' : 'lba.principal.sina.status_disabled') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="p-8 text-center text-slate-500">{{ __('lba.principal.sina.providers_empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{-- 3. Usage register. --}}
        <section class="principal-card grid gap-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-navy-900">{{ __('lba.principal.sina.usage_title') }}</h2>
                    <p class="text-xs text-slate-500">{{ __('lba.principal.sina.usage_description') }}</p>
                </div>
                <dl class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                    <div class="text-end">
                        <dt class="text-slate-500">{{ __('lba.principal.sina.usage_turns') }}</dt>
                        <dd class="font-semibold text-navy-900">{{ $usageTotals['turns'] }}</dd>
                    </div>
                    <div class="text-end">
                        <dt class="text-slate-500">{{ __('lba.principal.sina.usage_total_tokens') }}</dt>
                        <dd class="font-semibold text-navy-900">{{ $usageTotals['tokens'] }}</dd>
                    </div>
                    <div class="text-end">
                        <dt class="text-slate-500">{{ __('lba.principal.sina.usage_total_credits') }}</dt>
                        <dd class="font-semibold text-navy-900">{{ $usageTotals['credits'] }}</dd>
                    </div>
                </dl>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            @foreach (['usage_tenant', 'usage_provider', 'usage_model', 'usage_tokens', 'usage_credits', 'usage_recorded_at'] as $column)
                                <th scope="col" class="px-4 py-3 text-start">{{ __('lba.principal.sina.'.$column) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($usage as $record)
                            <tr>
                                <td class="px-4 py-3 font-semibold">{{ $record->tenant?->name ?? $record->tenant_id }}</td>
                                <td class="px-4 py-3">{{ $record->provider }}</td>
                                <td class="px-4 py-3">{{ $record->model_name }}</td>
                                <td class="px-4 py-3">{{ (int) $record->prompt_tokens + (int) $record->completion_tokens }}</td>
                                <td class="px-4 py-3">{{ $record->credit_units }}</td>
                                <td class="px-4 py-3">{{ $record->recorded_at?->translatedFormat('d M Y H:i') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-8 text-center text-slate-500">{{ __('lba.principal.sina.usage_empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
