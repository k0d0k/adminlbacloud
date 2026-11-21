@php
    use Filament\Support\Icons\Heroicon;

    // Icon map for every TailAdmin navigation key. Mirrors the Filament panel's
    // resource icons so the workspace feels consistent with the rest of LBA Cloud.
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

    $isActive = static function (string $key): bool {
        if (request()->route('registry') === $key) {
            return true;
        }

        return match ($key) {
            'reports' => request()->routeIs('clinic.tailadmin.reports'),
            'reviews' => request()->routeIs('clinic.tailadmin.reviews.*'),
            'pos' => request()->routeIs('clinic.tailadmin.pos*'),
            'transactions' => request()->routeIs('clinic.tailadmin.transactions'),
            'wallet' => request()->routeIs('clinic.tailadmin.wallet'),
            'products' => request()->routeIs('clinic.tailadmin.products*'),
            'inventory' => request()->routeIs('clinic.tailadmin.inventory*'),
            'orders' => request()->routeIs('clinic.tailadmin.orders*'),
            default => request()->routeIs('clinic.tailadmin.'.$key)
                && in_array($key, ['locations', 'events', 'sina', 'team', 'settings', 'site'], true),
        };
    };
@endphp
<a href="{{ route('clinic.tailadmin.dashboard') }}" class="principal-sidebar__brand mb-8 flex items-center gap-3 px-3 font-semibold">
    <span aria-hidden="true" class="grid size-9 shrink-0 place-items-center rounded-xl bg-navy-900 text-sm text-white">LBA</span>
    <span class="principal-sidebar__brand-name">LBA Cloud</span>
</a>
<nav aria-label="{{ __('principal.shell.navigation') }}" class="flex flex-col gap-1">
    <a class="principal-link" href="{{ route('clinic.tailadmin.dashboard') }}" @if (request()->routeIs('clinic.tailadmin.dashboard')) aria-current="page" @endif title="{{ __('principal.clinic.workspace') }}">
        <x-filament::icon :icon="Heroicon::OutlinedSquares2x2" class="principal-link__icon size-5" />
        <span class="principal-link__label">{{ __('principal.clinic.workspace') }}</span>
    </a>

    <p class="principal-sidebar__group mt-4 px-3 text-xs font-semibold tracking-wide uppercase">{{ __('lba.nav.clinic.group_operations') }}</p>

    @foreach ($links as $key => $name)
        @if (in_array($key, ['settings', 'site', 'wallet', 'transactions', 'orders', 'inventory', 'products', 'pos'], true))
            @continue
        @endif
        <a class="principal-link" href="{{ \App\Http\Controllers\Clinic\WorkspaceController::navUrl($key) }}" @if ($isActive($key)) aria-current="page" @endif title="{{ $name }}">
            <x-filament::icon :icon="$icons[$key] ?? Heroicon::OutlinedCircleStack" class="principal-link__icon size-5" />
            <span class="principal-link__label">{{ $name }}</span>
        </a>
    @endforeach

    <p class="principal-sidebar__group mt-4 px-3 text-xs font-semibold tracking-wide uppercase">{{ __('lba.nav.clinic.group_commerce') }}</p>

    @foreach (['pos', 'products', 'inventory', 'orders', 'transactions', 'wallet'] as $key)
        @if (isset($links[$key]))
            <a class="principal-link" href="{{ \App\Http\Controllers\Clinic\WorkspaceController::navUrl($key) }}" @if ($isActive($key)) aria-current="page" @endif title="{{ $links[$key] }}">
                <x-filament::icon :icon="$icons[$key] ?? Heroicon::OutlinedCircleStack" class="principal-link__icon size-5" />
                <span class="principal-link__label">{{ $links[$key] }}</span>
            </a>
        @endif
    @endforeach

    @if (isset($links['settings']) || isset($links['site']))
        <p class="principal-sidebar__group mt-4 px-3 text-xs font-semibold tracking-wide uppercase">{{ __('lba.nav.clinic.group_settings') }}</p>
        @foreach (['settings', 'site'] as $key)
            @if (isset($links[$key]))
                <a class="principal-link" href="{{ \App\Http\Controllers\Clinic\WorkspaceController::navUrl($key) }}" @if ($isActive($key)) aria-current="page" @endif title="{{ $links[$key] }}">
                    <x-filament::icon :icon="$icons[$key] ?? Heroicon::OutlinedCircleStack" class="principal-link__icon size-5" />
                    <span class="principal-link__label">{{ $links[$key] }}</span>
                </a>
            @endif
        @endforeach
    @endif

    {{-- Menu Dinamis — clinic-panel groups/entries the Principal composed in
         the navigation builder. Visibility is re-derived per acting user by
         `treeFor()`, so an entry gated by a permission or role they lack never
         renders for them. --}}
    @foreach (App\Models\Menu::treeFor(auth()->user(), App\Models\Menu::PANEL_CLINIC) as $dynamicGroup)
        <p class="principal-sidebar__group mt-4 px-3 text-xs font-semibold tracking-wide uppercase">{{ $dynamicGroup['group_label'] }}</p>
        @foreach ($dynamicGroup['items'] as $dynamicItem)
            @include('principal.layouts._menu-item', ['item' => $dynamicItem])
        @endforeach
    @endforeach

    <a class="principal-link" href="{{ route('filament.clinic.pages.dashboard') }}" title="{{ __('principal.clinic.legacy') }}">
        <x-filament::icon :icon="Heroicon::OutlinedChartBar" class="principal-link__icon size-5" />
        <span class="principal-link__label">{{ __('principal.clinic.legacy') }}</span>
    </a>
</nav>
