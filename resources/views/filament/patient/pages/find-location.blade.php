{{--
    Phase 8A — "Cari Lokasi LBA".

    A read-only list of the verified tenant's ACTIVE (published) branches, with
    a manual city / postal-code search. When — and only when — the patient
    grants the browser's geolocation permission the list is re-ordered
    nearest-first. A denied/revoked permission simply leaves the manual search
    in place. Only public-facing location details are shown; no patient,
    examination or staff data.
--}}
<x-filament-panels::page>
    <div class="space-y-4">
        <div class="flex flex-wrap items-end gap-2">
            <div class="min-w-[16rem] flex-1">
                <label for="location-search" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                    {{ __('lba.patient.locations.search_label') }}
                </label>

                <x-filament::input.wrapper>
                    <x-filament::input
                        type="search"
                        id="location-search"
                        wire:model.live.debounce.400ms="search"
                        placeholder="{{ __('lba.patient.locations.search_placeholder') }}"
                    />
                </x-filament::input.wrapper>
            </div>

            <x-filament::button
                type="button"
                size="sm"
                color="gray"
                x-data
                x-on:click="
                    if (! navigator.geolocation) { return; }
                    navigator.geolocation.getCurrentPosition(
                        (position) => $wire.useNearest(position.coords.latitude, position.coords.longitude),
                        () => {}
                    );
                "
            >
                {{ __('lba.patient.locations.use_nearest') }}
            </x-filament::button>

            @if ($this->isUsingNearest())
                <x-filament::button type="button" size="sm" color="gray" wire:click="clearNearest">
                    {{ __('lba.patient.locations.clear_nearest') }}
                </x-filament::button>
            @endif
        </div>

        @if ($this->isUsingNearest())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('lba.patient.locations.sorted_by_distance') }}
            </p>
        @endif

        @php($branches = $this->branches())

        @if ($branches->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('lba.patient.locations.empty') }}
            </p>
        @else
            <ul role="list" class="space-y-3">
                @foreach ($branches as $branch)
                    <li class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="font-semibold text-gray-950 dark:text-white">
                                    {{ $branch->name }}
                                </p>

                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $this->addressLine($branch) }}
                                </p>

                                @if (filled($branch->phone) || filled($branch->email))
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        @if (filled($branch->phone))
                                            <span>{{ $branch->phone }}</span>
                                        @endif
                                        @if (filled($branch->phone) && filled($branch->email))
                                            <span aria-hidden="true">·</span>
                                        @endif
                                        @if (filled($branch->email))
                                            <span>{{ $branch->email }}</span>
                                        @endif
                                    </p>
                                @endif
                            </div>

                            @if ($this->distanceLabel($branch) !== null)
                                <span class="shrink-0 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                    {{ $this->distanceLabel($branch) }}
                                </span>
                            @endif
                        </div>

                        <a
                            href="{{ $this->bookingUrl($branch) }}"
                            class="mt-3 inline-block text-sm font-semibold text-primary-600 underline dark:text-primary-400"
                        >
                            {{ __('lba.patient.locations.book') }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-filament-panels::page>
