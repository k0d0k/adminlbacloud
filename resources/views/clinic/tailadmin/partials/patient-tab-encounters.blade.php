{{--
    Kunjungan & SOAP — the encounter chronology (Phase 3).

    A patient has MANY encounters and every one is its own row: the list is the
    longitudinal history, and opening an encounter never touches an earlier one.
    Each row states its lifecycle (draft / final / amended) and how many vitals,
    glucose readings and lab results it carries, so a clinician can see at a
    glance which visit still needs finishing.
--}}
<div class="grid gap-4">
    @if ($canManageClinical)
        <section class="principal-card p-5">
            <h3 class="mb-3 font-semibold text-navy-900">{{ __('lba.clinical.encounters.new') }}</h3>
            <form method="post" action="{{ route('clinic.tailadmin.patients.encounters.store', $patient) }}" class="grid gap-3 sm:grid-cols-2">
                @csrf
                <label class="grid gap-1 text-sm">
                    {{ __('lba.clinical.encounters.type') }}
                    <select name="type" class="principal-input">
                        @foreach (\Modules\Patients\Models\ClinicalEncounter::TYPES as $type)
                            <option value="{{ $type }}" @selected(old('type') === $type)>{{ __('lba.clinical.encounters.type_'.$type) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-sm">
                    {{ __('lba.clinical.encounters.at') }}
                    <input class="principal-input" type="datetime-local" name="encounter_at" value="{{ old('encounter_at', now()->format('Y-m-d\TH:i')) }}">
                </label>
                <button class="principal-button principal-button-primary justify-self-start">{{ __('lba.clinical.encounters.new') }}</button>
            </form>
        </section>
    @endif

    <section class="principal-card">
        <div class="border-b border-slate-200 p-4">
            <h3 class="font-semibold text-navy-900">{{ __('lba.clinical.encounters.title') }}</h3>
        </div>
        <ul class="divide-y divide-slate-100">
            @forelse ($clinical['encounters'] as $encounter)
                <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <p class="font-medium">
                            {{ $encounter->encounter_at?->translatedFormat('d M Y H:i') ?? '—' }}
                            <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ __('lba.clinical.encounters.type_'.$encounter->type) }}</span>
                            <span class="ml-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $encounter->isDraft() ? 'bg-amber-100 text-amber-700' : ($encounter->isAmended() ? 'bg-purple-100 text-purple-700' : 'bg-emerald-100 text-emerald-700') }}">
                                {{ __('lba.clinical.encounters.status_'.$encounter->status) }}
                            </span>
                        </p>
                        <p class="text-sm text-slate-600">
                            {{ __('lba.clinical.encounters.vitals_count', ['count' => $encounter->vitals_count]) }}
                            · {{ __('lba.clinical.encounters.glucose_count', ['count' => $encounter->glucose_readings_count]) }}
                            · {{ __('lba.clinical.encounters.labs_count', ['count' => $encounter->lab_results_count]) }}
                        </p>
                        @if ($encounter->chief_complaint)
                            <p class="wrap-break-word text-sm text-slate-500">{{ $encounter->chief_complaint }}</p>
                        @endif
                    </div>
                    <a class="principal-button" href="{{ route('clinic.tailadmin.patients.encounters.show', [$patient, $encounter]) }}">{{ __('lba.clinical.encounters.open') }}</a>
                </li>
            @empty
                <li class="p-8 text-center text-sm text-slate-500">{{ __('lba.clinical.encounters.empty') }}</li>
            @endforelse
        </ul>
    </section>
</div>
