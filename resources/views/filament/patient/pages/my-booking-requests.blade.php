{{--
    Website CMS (Task 11) — "Permintaan Booking".

    Lists only the acting identity's OWN verified (but not yet confirmed)
    booking requests and lets them confirm one WITHOUT re-entering the branch,
    service or slot they already chose on the public form. Confirmation runs
    through the same service the public flow feeds, so the slot is re-validated
    and a retry never books twice.
--}}
<x-filament-panels::page>
    @php($requests = $this->requests())

    @if ($requests->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('lba.patient.booking_requests.empty') }}
        </p>
    @else
        <ul role="list" class="space-y-3">
            @foreach ($requests as $bookingRequest)
                <li class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="font-semibold text-gray-950 dark:text-white">
                                {{ $bookingRequest->scheduled_at?->translatedFormat('d M Y · H:i') }}
                            </p>

                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ $bookingRequest->branch?->name ?? '—' }}
                                <span aria-hidden="true">·</span>
                                {{ __('lba.patient.booking.services.'.$bookingRequest->service_type) }}
                            </p>
                        </div>

                        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium">
                            {{ __('lba.patient.booking_requests.status.'.$bookingRequest->status) }}
                        </span>
                    </div>

                    <div class="mt-3">
                        <x-filament::button
                            size="sm"
                            wire:click="confirm('{{ $bookingRequest->getKey() }}')"
                            wire:confirm="{{ __('lba.patient.booking_requests.confirm_prompt') }}"
                        >
                            {{ __('lba.patient.booking_requests.confirm') }}
                        </x-filament::button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament-panels::page>
