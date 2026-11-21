{{--
    Phase 8A — "Janji Saya".

    Lists only the acting identity's own appointments; a cancellable one exposes
    a cancel action that runs through the server-verified transition.
--}}
<x-filament-panels::page>
    @php($appointments = $this->appointments())

    @if ($appointments->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('lba.patient.appointments.empty') }}
        </p>
    @else
        <ul role="list" class="space-y-3">
            @foreach ($appointments as $appointment)
                <li class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="font-semibold text-gray-950 dark:text-white">
                                {{ $appointment->starts_at?->translatedFormat('d M Y · H:i') }}
                            </p>

                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ $appointment->branch?->name ?? '—' }}
                                <span aria-hidden="true">·</span>
                                {{ __('lba.patient.booking.services.'.$appointment->service_type) }}
                            </p>
                        </div>

                        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium">
                            {{ $this->statusLabel($appointment->status) }}
                        </span>
                    </div>

                    @if ($this->canReschedule($appointment) || $this->canCancel($appointment))
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            @if ($this->canReschedule($appointment))
                                <x-filament::button
                                    size="sm"
                                    color="gray"
                                    wire:click="beginReschedule('{{ $appointment->getKey() }}')"
                                >
                                    {{ __('lba.patient.appointments.reschedule') }}
                                </x-filament::button>
                            @endif

                            @if ($this->canCancel($appointment))
                                <x-filament::button
                                    size="sm"
                                    color="danger"
                                    wire:click="cancel('{{ $appointment->getKey() }}')"
                                    wire:confirm="{{ __('lba.patient.appointments.confirm_cancel') }}"
                                >
                                    {{ __('lba.patient.appointments.cancel') }}
                                </x-filament::button>
                            @endif
                        </div>
                    @endif

                    @if ($this->reschedulingId === (string) $appointment->getKey())
                        <form
                            wire:submit="reschedule"
                            class="mt-3 space-y-2 rounded-lg border border-gray-200 p-3 dark:border-white/10"
                        >
                            <label
                                for="reschedule-{{ $appointment->getKey() }}"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-200"
                            >
                                {{ __('lba.patient.appointments.new_schedule') }}
                            </label>

                            <x-filament::input.wrapper>
                                <x-filament::input
                                    type="datetime-local"
                                    id="reschedule-{{ $appointment->getKey() }}"
                                    wire:model="rescheduleStartsAt"
                                />
                            </x-filament::input.wrapper>

                            @error('rescheduleStartsAt')
                                <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                            @enderror

                            <div class="flex flex-wrap items-center gap-2">
                                <x-filament::button type="submit" size="sm">
                                    {{ __('lba.patient.appointments.save_schedule') }}
                                </x-filament::button>

                                <x-filament::button
                                    type="button"
                                    size="sm"
                                    color="gray"
                                    wire:click="cancelReschedule"
                                >
                                    {{ __('lba.patient.appointments.cancel_reschedule') }}
                                </x-filament::button>
                            </div>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-filament-panels::page>
