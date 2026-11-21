@extends('principal.layouts.app', ['title' => __('principal.clinic.workspace'), 'panel' => 'clinic'])

@php
    use Filament\Support\Icons\Heroicon;

    $icons = [
        'patients' => Heroicon::OutlinedUsers,
        'appointments' => Heroicon::OutlinedCalendarDays,
        'examinations' => Heroicon::OutlinedBeaker,
        'consultations' => Heroicon::OutlinedChatBubbleLeftRight,
        'practitioners' => Heroicon::OutlinedUserGroup,
        'reviews' => Heroicon::OutlinedClipboardDocumentCheck,
        'reports' => Heroicon::OutlinedDocumentText,
        'locations' => Heroicon::OutlinedMapPin,
        'team' => Heroicon::OutlinedUsers,
        'events' => Heroicon::OutlinedCalendar,
        'sina' => Heroicon::OutlinedSparkles,
        'pos' => Heroicon::OutlinedShoppingCart,
        'products' => Heroicon::OutlinedShoppingBag,
        'inventory' => Heroicon::OutlinedArchiveBox,
        'orders' => Heroicon::OutlinedTruck,
        'transactions' => Heroicon::OutlinedBanknotes,
        'wallet' => Heroicon::OutlinedWallet,
        'settings' => Heroicon::OutlinedCog6Tooth,
        'site' => Heroicon::OutlinedGlobeAlt,
    ];

    // Accent colour per navigation key for the colourful card grid.
    $tones = [
        'patients' => 'from-teal-500 to-teal-600',
        'appointments' => 'from-sky-500 to-sky-600',
        'examinations' => 'from-indigo-500 to-indigo-600',
        'consultations' => 'from-violet-500 to-violet-600',
        'practitioners' => 'from-amber-500 to-amber-600',
        'reviews' => 'from-rose-500 to-rose-600',
        'reports' => 'from-emerald-500 to-emerald-600',
        'locations' => 'from-cyan-500 to-cyan-600',
        'team' => 'from-fuchsia-500 to-fuchsia-600',
        'events' => 'from-orange-500 to-orange-600',
        'sina' => 'from-purple-500 to-purple-600',
        'pos' => 'from-pink-500 to-pink-600',
        'products' => 'from-lime-600 to-lime-700',
        'inventory' => 'from-stone-500 to-stone-600',
        'orders' => 'from-blue-500 to-blue-600',
        'transactions' => 'from-green-500 to-green-600',
        'wallet' => 'from-teal-600 to-teal-700',
        'settings' => 'from-slate-500 to-slate-600',
        'site' => 'from-indigo-500 to-indigo-600',
    ];

    $stats = $stats ?? ['patients' => null, 'today' => null, 'pending_exams' => null, 'pending_reviews' => null];
    $statValue = static fn ($v) => $v === null ? '—' : (string) $v;
@endphp

@section('content')
    {{-- Hero band with verified tenant/branch context and live operational stats. --}}
    <section class="relative mb-6 overflow-hidden rounded-3xl bg-gradient-to-br from-navy-900 via-navy-900 to-brand-700 p-6 text-white shadow-lg sm:p-8">
        <div class="absolute -right-10 -top-10 size-48 rounded-full bg-brand-500/20 blur-3xl" aria-hidden="true"></div>
        <div class="absolute -bottom-16 right-24 size-40 rounded-full bg-teal-400/10 blur-3xl" aria-hidden="true"></div>

        <div class="relative">
            <p class="text-xs font-semibold uppercase tracking-widest text-brand-200">{{ __('lba.widget.hero_eyebrow_clinic') }}</p>
            <h1 class="mt-1 text-2xl font-bold sm:text-3xl">{{ __('lba.widget.hero_title_clinic') }}</h1>
            <p class="mt-2 max-w-2xl text-sm text-white/70">{{ __('lba.widget.hero_subtitle_clinic') }}</p>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                @if ($tenant?->name)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-medium backdrop-blur">
                        <x-filament::icon :icon="Heroicon::OutlinedBuildingOffice2" class="size-3.5" />
                        {{ $tenant->name }}
                    </span>
                @endif
                @if ($branch?->name)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-medium backdrop-blur">
                        <x-filament::icon :icon="Heroicon::OutlinedMapPin" class="size-3.5" />
                        {{ $branch->name }}
                    </span>
                @endif
                <span class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-medium backdrop-blur">
                    <x-filament::icon :icon="Heroicon::OutlinedCalendarDays" class="size-3.5" />
                    {{ now()->translatedFormat('l, j F Y') }}
                </span>
            </div>

            {{-- Live KPI strip --}}
            <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-2xl bg-white/10 p-4 backdrop-blur">
                    <div class="flex items-center gap-2 text-white/60">
                        <x-filament::icon :icon="Heroicon::OutlinedUsers" class="size-4" />
                        <span class="text-xs font-medium">{{ __('lba.kpi.clinic.patients_label') }}</span>
                    </div>
                    <p class="mt-1 text-2xl font-bold">{{ $statValue($stats['patients']) }}</p>
                </div>
                <div class="rounded-2xl bg-white/10 p-4 backdrop-blur">
                    <div class="flex items-center gap-2 text-white/60">
                        <x-filament::icon :icon="Heroicon::OutlinedCalendarDays" class="size-4" />
                        <span class="text-xs font-medium">{{ __('lba.kpi.clinic.today_appointments_label') }}</span>
                    </div>
                    <p class="mt-1 text-2xl font-bold">{{ $statValue($stats['today']) }}</p>
                </div>
                <div class="rounded-2xl bg-white/10 p-4 backdrop-blur">
                    <div class="flex items-center gap-2 text-white/60">
                        <x-filament::icon :icon="Heroicon::OutlinedBeaker" class="size-4" />
                        <span class="text-xs font-medium">{{ __('lba.kpi.workload.pending_examinations_label') }}</span>
                    </div>
                    <p class="mt-1 text-2xl font-bold">{{ $statValue($stats['pending_exams']) }}</p>
                </div>
                <div class="rounded-2xl bg-white/10 p-4 backdrop-blur">
                    <div class="flex items-center gap-2 text-white/60">
                        <x-filament::icon :icon="Heroicon::OutlinedClipboardDocumentCheck" class="size-4" />
                        <span class="text-xs font-medium">{{ __('lba.kpi.workload.pending_reviews_label') }}</span>
                    </div>
                    <p class="mt-1 text-2xl font-bold">{{ $statValue($stats['pending_reviews']) }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Tenant / branch switcher (compact) --}}
    @if (count($tenantOptions) > 1 || count($branchOptions) > 1 || $branchSetupUrl)
        <div class="mb-6 grid gap-4 md:grid-cols-2">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="flex items-center gap-2 font-semibold text-navy-900">
                    <x-filament::icon :icon="Heroicon::OutlinedBuildingOffice2" class="size-5 text-brand-700" />
                    {{ __('lba.switcher.current_aria') }}
                </h2>
                <p class="mt-1 text-sm text-slate-500">{{ $tenant->name ?? '—' }}</p>
                @if (count($tenantOptions) > 1)
                    <form method="post" action="{{ route('clinic.tailadmin.switch-tenant') }}" class="mt-3 flex flex-wrap gap-2">
                        @csrf
                        <select name="tenant_id" class="principal-input flex-1" aria-label="{{ __('lba.switcher.choose') }}" required>
                            @foreach ($tenantOptions as $option)
                                <option value="{{ $option->id }}" @selected($option->id === $tenant->getKey())>{{ $option->name }}{{ $option->brandLabel ? ' · '.$option->brandLabel : '' }}</option>
                            @endforeach
                        </select>
                        <button class="principal-button principal-button-primary">{{ __('principal.clinic.apply_selection') }}</button>
                    </form>
                @endif
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="flex items-center gap-2 font-semibold text-navy-900">
                    <x-filament::icon :icon="Heroicon::OutlinedMapPin" class="size-5 text-brand-700" />
                    {{ __('lba.branch_switcher.current_aria') }}
                </h2>
                <p class="mt-1 text-sm text-slate-500">{{ $branch?->name ?? '—' }}</p>
                @if (count($branchOptions) > 1)
                    <form method="post" action="{{ route('clinic.tailadmin.switch-branch') }}" class="mt-3 flex flex-wrap gap-2">
                        @csrf
                        <input type="hidden" name="tenant_id" value="{{ $tenant->id }}">
                        <select name="branch_id" class="principal-input flex-1" aria-label="{{ __('lba.branch_switcher.choose') }}" required>
                            <option value="">{{ __('lba.branch_switcher.choose') }}</option>
                            @foreach ($branchOptions as $option)
                                <option value="{{ $option->id }}" @selected($option->id === $branch?->getKey())>{{ $option->name }}</option>
                            @endforeach
                        </select>
                        <button class="principal-button principal-button-primary">{{ __('principal.clinic.apply_selection') }}</button>
                    </form>
                @elseif ($branchOptions === [] && $branchSetupUrl)
                    <a class="principal-button principal-button-primary mt-3" href="{{ $branchSetupUrl }}">{{ __('lba.branch_switcher.setup') }}</a>
                @endif
            </section>
        </div>
    @endif

    {{-- Colourful navigation grid --}}
    <h2 class="mb-3 text-lg font-semibold text-navy-900">{{ __('principal.clinic.workspace') }}</h2>
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
        @foreach ($links as $key => $label)
            <a href="{{ \App\Http\Controllers\Clinic\WorkspaceController::navUrl($key) }}"
               class="group relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition-all hover:-translate-y-1 hover:shadow-lg">
                <div class="absolute right-0 top-0 h-16 w-16 -translate-y-6 translate-x-6 rounded-full bg-gradient-to-br {{ $tones[$key] ?? 'from-slate-400 to-slate-500' }} opacity-10 transition-opacity group-hover:opacity-20" aria-hidden="true"></div>
                <div class="relative">
                    <span class="inline-flex size-11 items-center justify-center rounded-xl bg-gradient-to-br {{ $tones[$key] ?? 'from-slate-400 to-slate-500' }} text-white shadow-md">
                        <x-filament::icon :icon="$icons[$key] ?? Heroicon::OutlinedCircleStack" class="size-5" />
                    </span>
                    <p class="mt-3 font-semibold text-navy-900">{{ $label }}</p>
                    <span class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-700 opacity-0 transition-opacity group-hover:opacity-100">
                        {{ __('lba.nav.clinic.open') }}
                        <x-filament::icon :icon="Heroicon::OutlinedArrowRight" class="size-3" />
                    </span>
                </div>
            </a>
        @endforeach
    </div>
@endsection
