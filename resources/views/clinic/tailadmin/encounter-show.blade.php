{{--
    Rekam Medis — one clinical encounter (Phase 3/4).

    A patient has MANY encounters and this page writes to exactly ONE of them:
    the SOAP note, the vital sets, the glucose readings and the lab results all
    hang off this encounter row, so nothing here can ever overwrite an earlier
    visit.

    A DRAFT is edited directly; a FINAL/amended note is frozen and shown
    read-only — the only path that changes it is the audited amendment form
    below, which requires a reason. This page also hands the encounter into the
    existing LBA examination module (Phase 4) without copying any clinical data.
--}}
@extends('principal.layouts.app', ['title' => __('lba.clinical.encounters.title'), 'panel' => 'clinic'])

@section('content')
    <div class="grid gap-4">
        @if ($errors->any())
            <p role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</p>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <a class="principal-button" href="{{ route('clinic.tailadmin.patients.show', ['patient' => $patient, 'tab' => 'encounters']) }}">{{ __('lba.patients.back') }}</a>
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full px-3 py-1 text-sm font-medium {{ $encounter->isDraft() ? 'bg-amber-100 text-amber-700' : ($encounter->isAmended() ? 'bg-purple-100 text-purple-700' : 'bg-emerald-100 text-emerald-700') }}">
                    {{ __('lba.clinical.encounters.status_'.$encounter->status) }}
                </span>
                @if ($canFinalize && $encounter->isDraft())
                    <form method="post" action="{{ route('clinic.tailadmin.patients.encounters.finalize', [$patient, $encounter]) }}">
                        @csrf
                        <button class="principal-button principal-button-primary" type="submit">{{ __('lba.clinical.soap.finalize') }}</button>
                    </form>
                @endif
            </div>
        </div>

        {{-- ------------------------------------------------ Header + alerts --}}
        <section class="principal-card p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="wrap-break-word font-semibold text-navy-900">{{ $patient->full_name }}</h2>
                    <p class="text-sm text-slate-600">
                        {{ $patient->patient_number }}
                        @if ($patient->age() !== null) · {{ __('lba.clinical.age_years', ['age' => $patient->age()]) }} @endif
                        @if ($patient->sex !== null) · {{ __('lba.patients.sex_'.$patient->sex) }} @endif
                    </p>
                    <p class="mt-1 text-sm text-slate-600">
                        {{ __('lba.clinical.encounters.at') }}: {{ $encounter->encounter_at?->translatedFormat('d M Y H:i') ?? '—' }}
                        · {{ __('lba.clinical.encounters.type') }}: {{ __('lba.clinical.encounters.type_'.$encounter->type) }}
                    </p>
                </div>
                @if (! $encounter->isDraft())
                    <div class="text-sm text-slate-500">
                        @if ($encounter->finalized_at)
                            <p>{{ __('lba.clinical.soap.finalized_at') }}: {{ $encounter->finalized_at->translatedFormat('d M Y H:i') }}</p>
                        @endif
                        @if ($encounter->amended_at)
                            <p>{{ __('lba.clinical.soap.amended_at') }}: {{ $encounter->amended_at->translatedFormat('d M Y H:i') }}</p>
                        @endif
                        <p>{{ __('lba.clinical.soap.revision') }}: {{ $encounter->revision }}</p>
                    </div>
                @endif
            </div>

            <div class="mt-3 grid gap-2 rounded-lg border border-slate-200 p-3 lg:grid-cols-3">
                <div class="min-w-0">
                    <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ __('lba.clinical.alerts.allergies') }}</p>
                    @forelse ($alerts['allergies'] as $allergy)
                        <p class="wrap-break-word text-sm font-medium text-red-700">{{ $allergy->substance }}@if ($allergy->reaction) · {{ $allergy->reaction }}@endif</p>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('lba.clinical.alerts.none') }}</p>
                    @endforelse
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ __('lba.clinical.alerts.conditions') }}</p>
                    @forelse ($alerts['conditions'] as $condition)
                        <p class="wrap-break-word text-sm font-medium">{{ $condition->name }}@if ($condition->icd10_code) · {{ $condition->icd10_code }}@endif</p>
                    @empty
                        <p class="text-sm text-slate-500">—</p>
                    @endforelse
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ __('lba.clinical.alerts.medications') }}</p>
                    @forelse ($alerts['medications'] as $medication)
                        <p class="wrap-break-word text-sm font-medium">{{ $medication->name }}@if ($medication->dose) · {{ $medication->dose }}@endif</p>
                    @empty
                        <p class="text-sm text-slate-500">—</p>
                    @endforelse
                </div>
            </div>
        </section>

        {{-- ------------------------------------------------------- SOAP note --}}
        <section class="principal-card p-5">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-semibold text-navy-900">{{ __('lba.clinical.soap.title') }}</h3>
                <p class="text-sm {{ $encounter->isEditable() ? 'text-brand-700' : 'text-slate-500' }}">
                    {{ $encounter->isEditable() ? __('lba.clinical.soap.draft_notice') : __('lba.clinical.soap.frozen_notice') }}
                </p>
            </div>

            @if ($encounter->isEditable() && $canManage)
                <form method="post" action="{{ route('clinic.tailadmin.patients.encounters.update', [$patient, $encounter]) }}" class="grid gap-3">
                    @csrf
                    @method('put')
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.encounters.at') }}
                            <input class="principal-input" type="datetime-local" name="encounter_at" value="{{ old('encounter_at', $encounter->encounter_at?->format('Y-m-d\TH:i')) }}">
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.encounters.type') }}
                            <select class="principal-input" name="type">
                                @foreach (\Modules\Patients\Models\ClinicalEncounter::TYPES as $type)
                                    <option value="{{ $type }}" @selected(old('type', $encounter->type) === $type)>{{ __('lba.clinical.encounters.type_'.$type) }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach (['chief_complaint', 'history_of_present_illness', 'physical_exam', 'supporting_findings'] as $field)
                            <label class="grid gap-1 text-sm">
                                {{ __('lba.clinical.soap.'.$field) }}
                                <textarea class="principal-input" name="{{ $field }}" rows="2" maxlength="5000">{{ old($field, $encounter->{$field}) }}</textarea>
                            </label>
                        @endforeach
                    </div>
                    <div class="grid gap-3">
                        @foreach (['subjective', 'objective', 'assessment', 'plan'] as $field)
                            <label class="grid gap-1 text-sm">
                                {{ __('lba.clinical.soap.'.$field) }}
                                <textarea class="principal-input" name="{{ $field }}" rows="3" maxlength="5000">{{ old($field, $encounter->{$field}) }}</textarea>
                                <span class="text-xs text-slate-500">{{ __('lba.clinical.soap.'.$field.'_hint') }}</span>
                            </label>
                        @endforeach
                    </div>
                    <button class="principal-button principal-button-primary justify-self-start" type="submit">{{ __('lba.clinical.soap.save_draft') }}</button>
                </form>
            @else
                <dl class="grid gap-3 sm:grid-cols-2">
                    @foreach (['chief_complaint', 'history_of_present_illness', 'physical_exam', 'supporting_findings', 'subjective', 'objective', 'assessment', 'plan'] as $field)
                        <div class="min-w-0">
                            <dt class="text-sm text-slate-500">{{ __('lba.clinical.soap.'.$field) }}</dt>
                            <dd class="wrap-break-word whitespace-pre-line font-medium">{{ $encounter->{$field} ?: '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif

            @if ($canFinalize && ! $encounter->isDraft())
                <div class="mt-5 border-t border-slate-200 pt-4">
                    <h4 class="mb-2 font-medium text-navy-900">{{ __('lba.clinical.soap.amend') }}</h4>
                    <form method="post" action="{{ route('clinic.tailadmin.patients.encounters.amend', [$patient, $encounter]) }}" class="grid gap-3">
                        @csrf
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.soap.amend_reason') }}
                            <input class="principal-input" name="reason" maxlength="500" required>
                        </label>
                        <div class="grid gap-3">
                            @foreach (['subjective', 'objective', 'assessment', 'plan'] as $field)
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.soap.'.$field) }}
                                    <textarea class="principal-input" name="{{ $field }}" rows="2" maxlength="5000">{{ $encounter->{$field} }}</textarea>
                                </label>
                            @endforeach
                        </div>
                        <button class="principal-button justify-self-start" type="submit">{{ __('lba.clinical.soap.amend') }}</button>
                    </form>
                </div>
            @endif

            @if ($encounter->amendments->isNotEmpty())
                <div class="mt-5 border-t border-slate-200 pt-4">
                    <h4 class="mb-2 font-medium text-navy-900">{{ __('lba.clinical.soap.amendments') }}</h4>
                    <ul class="divide-y divide-slate-100">
                        @foreach ($encounter->amendments->sortByDesc('occurred_at') as $amendment)
                            <li class="py-2 text-sm">
                                <p class="font-medium">{{ $amendment->occurred_at?->translatedFormat('d M Y H:i') ?? '—' }} · {{ __('lba.clinical.soap.revision') }} {{ $amendment->revision }}</p>
                                <p class="text-slate-600">{{ __('lba.clinical.soap.reason') }}: {{ $amendment->reason }}</p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        {{-- ---------------------------------------------------- Vital signs --}}
        <section class="principal-card p-5">
            <h3 class="mb-3 font-semibold text-navy-900">{{ __('lba.clinical.vitals.title') }}</h3>

            @if ($canManage)
                <details class="mb-4">
                    <summary class="cursor-pointer text-sm font-medium text-brand-700">{{ __('lba.clinical.vitals.add') }}</summary>
                    <form method="post" action="{{ route('clinic.tailadmin.patients.encounters.measurements.store', [$patient, $encounter, 'vitals']) }}" class="mt-3 grid gap-3 sm:grid-cols-3">
                        @csrf
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.vitals.measured_at') }}
                            <input class="principal-input" type="datetime-local" name="measured_at" value="{{ old('measured_at', now()->format('Y-m-d\TH:i')) }}">
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.vitals.position') }}
                            <select class="principal-input" name="position">
                                <option value="">—</option>
                                @foreach (\Modules\Patients\Models\ClinicalVital::POSITIONS as $position)
                                    <option value="{{ $position }}" @selected(old('position') === $position)>{{ __('lba.clinical.vitals.position_'.$position) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.vitals.arm') }}
                            <select class="principal-input" name="arm">
                                <option value="">—</option>
                                @foreach (\Modules\Patients\Models\ClinicalVital::ARMS as $arm)
                                    <option value="{{ $arm }}" @selected(old('arm') === $arm)>{{ __('lba.clinical.vitals.arm_'.$arm) }}</option>
                                @endforeach
                            </select>
                        </label>
                        @foreach (['systolic' => 'systolic', 'diastolic' => 'diastolic', 'pulse' => 'pulse', 'respiratory_rate' => 'respiratory_rate', 'temperature_c' => 'temperature', 'oxygen_saturation' => 'oxygen_saturation', 'weight_kg' => 'weight', 'height_cm' => 'height'] as $name => $label)
                            <label class="grid gap-1 text-sm">
                                {{ __('lba.clinical.vitals.'.$label) }}
                                <input class="principal-input" type="number" step="any" name="{{ $name }}" value="{{ old($name) }}">
                            </label>
                        @endforeach
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.vitals.device') }}
                            <input class="principal-input" name="device" maxlength="120" value="{{ old('device') }}">
                        </label>
                        <label class="grid gap-1 text-sm sm:col-span-2">
                            {{ __('lba.clinical.vitals.notes') }}
                            <input class="principal-input" name="notes" maxlength="2000" value="{{ old('notes') }}">
                        </label>
                        <p class="text-xs text-slate-500 sm:col-span-3">{{ __('lba.clinical.vitals.not_measured') }} — {{ __('lba.clinical.glucose.value_hint') }}</p>
                        <button class="principal-button principal-button-primary justify-self-start sm:col-span-3" type="submit">{{ __('lba.clinical.vitals.add') }}</button>
                    </form>
                </details>
            @endif

            <ul class="divide-y divide-slate-100">
                @forelse ($encounter->vitals->sortBy('measured_at') as $vital)
                    <li class="py-3">
                        <p class="text-sm text-slate-500">{{ $vital->measured_at?->translatedFormat('d M Y H:i') ?? '—' }}</p>
                        @if ($vital->status === \Modules\Patients\Models\ClinicalVital::STATUS_NOT_MEASURED)
                            <p class="text-sm text-slate-500">{{ __('lba.clinical.vitals.not_measured') }}</p>
                        @else
                            <p class="font-medium">
                                @if ($vital->bloodPressureLabel()) {{ __('lba.clinical.vitals.blood_pressure') }}: {{ $vital->bloodPressureLabel() }} mmHg @endif
                                @if ($vital->pulse !== null) · {{ $vital->pulse }} bpm @endif
                                @if ($vital->respiratory_rate !== null) · {{ $vital->respiratory_rate }} /min @endif
                                @if ($vital->temperature_c !== null) · {{ $vital->temperature_c }} °C @endif
                                @if ($vital->oxygen_saturation !== null) · SpO₂ {{ $vital->oxygen_saturation }} % @endif
                            </p>
                            @if ($vital->weight_kg !== null || $vital->height_cm !== null || $vital->bmi !== null)
                                <p class="text-sm text-slate-600">
                                    @if ($vital->weight_kg !== null) {{ __('lba.clinical.vitals.weight') }}: {{ $vital->weight_kg }} @endif
                                    @if ($vital->height_cm !== null) · {{ __('lba.clinical.vitals.height') }}: {{ $vital->height_cm }} @endif
                                    @if ($vital->bmi !== null) · {{ __('lba.clinical.vitals.bmi') }}: {{ $vital->bmi }} @endif
                                </p>
                            @endif
                            <p class="text-xs text-slate-500">
                                @if ($vital->position) {{ __('lba.clinical.vitals.position_'.$vital->position) }} @endif
                                @if ($vital->arm) · {{ __('lba.clinical.vitals.arm_'.$vital->arm) }} @endif
                                @if ($vital->device) · {{ $vital->device }} @endif
                            </p>
                        @endif
                    </li>
                @empty
                    <li class="py-3 text-sm text-slate-500">{{ __('lba.clinical.vitals.empty') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- ------------------------------------------------- Blood glucose --}}
        <section class="principal-card p-5">
            <h3 class="mb-3 font-semibold text-navy-900">{{ __('lba.clinical.glucose.title') }}</h3>

            @if ($canManage)
                <details class="mb-4">
                    <summary class="cursor-pointer text-sm font-medium text-brand-700">{{ __('lba.clinical.glucose.add') }}</summary>
                    <form method="post" action="{{ route('clinic.tailadmin.patients.encounters.measurements.store', [$patient, $encounter, 'glucose']) }}" class="mt-3 grid gap-3 sm:grid-cols-3">
                        @csrf
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.glucose.kind') }}
                            <select class="principal-input" name="kind" required>
                                @foreach (\Modules\Patients\Models\ClinicalGlucoseReading::KINDS as $kind)
                                    <option value="{{ $kind }}" @selected(old('kind') === $kind)>{{ __('lba.clinical.glucose.kind_'.$kind) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.glucose.value') }}
                            <input class="principal-input" type="number" step="any" min="0" name="value" value="{{ old('value') }}">
                            <span class="text-xs text-slate-500">{{ __('lba.clinical.glucose.value_hint') }}</span>
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.glucose.unit') }}
                            <select class="principal-input" name="unit">
                                @foreach ($glucoseUnits as $unit)
                                    <option value="{{ $unit }}" @selected(old('unit', \Modules\Patients\Models\ClinicalGlucoseReading::CANONICAL_GLUCOSE_UNIT) === $unit)>{{ $unit }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.glucose.specimen') }}
                            <input class="principal-input" name="specimen" maxlength="64" value="{{ old('specimen') }}" placeholder="{{ __('lba.clinical.glucose.specimen_hint') }}">
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.glucose.method') }}
                            <input class="principal-input" name="method" maxlength="120" value="{{ old('method') }}">
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.glucose.source_name') }}
                            <input class="principal-input" name="source_name" maxlength="120" value="{{ old('source_name') }}">
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.glucose.measured_at') }}
                            <input class="principal-input" type="datetime-local" name="measured_at" value="{{ old('measured_at', now()->format('Y-m-d\TH:i')) }}">
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.glucose.last_meal_at') }}
                            <input class="principal-input" type="datetime-local" name="last_meal_at" value="{{ old('last_meal_at') }}">
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.glucose.notes') }}
                            <input class="principal-input" name="notes" maxlength="2000" value="{{ old('notes') }}">
                        </label>
                        <p class="text-xs text-slate-500 sm:col-span-3">{{ __('lba.clinical.glucose.conversion_notice') }}</p>
                        <button class="principal-button principal-button-primary justify-self-start sm:col-span-3" type="submit">{{ __('lba.clinical.glucose.add') }}</button>
                    </form>
                </details>
            @endif

            <ul class="divide-y divide-slate-100">
                @forelse ($encounter->glucoseReadings->sortBy('measured_at') as $reading)
                    <li class="py-3">
                        <p class="font-medium">{{ __('lba.clinical.glucose.kind_'.$reading->kind) }}</p>
                        <p class="text-sm text-slate-600">
                            @if ($reading->isMeasured())
                                {{ $reading->value }} {{ $reading->unit }}
                                @if ($reading->value_normalized !== null && $reading->normalized_unit !== $reading->unit)
                                    · {{ $reading->value_normalized }} {{ $reading->normalized_unit }}
                                @endif
                            @else
                                {{ __('lba.clinical.glucose.not_performed') }}
                            @endif
                        </p>
                        <p class="text-xs text-slate-500">{{ $reading->measured_at?->translatedFormat('d M Y H:i') ?? '—' }}@if ($reading->source_name) · {{ $reading->source_name }} @endif</p>
                    </li>
                @empty
                    <li class="py-3 text-sm text-slate-500">{{ __('lba.clinical.glucose.empty') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- ------------------------------------------------ Lab results --}}
        <section class="principal-card p-5">
            <h3 class="mb-3 font-semibold text-navy-900">{{ __('lba.clinical.labs.title') }}</h3>

            @if ($canManage)
                <details class="mb-4">
                    <summary class="cursor-pointer text-sm font-medium text-brand-700">{{ __('lba.clinical.labs.add') }}</summary>
                    <form method="post" action="{{ route('clinic.tailadmin.patients.encounters.measurements.store', [$patient, $encounter, 'labs']) }}" class="mt-3 grid gap-3 sm:grid-cols-3">
                        @csrf
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.labs.test_name') }}
                            <input class="principal-input" name="test_name" maxlength="255" value="{{ old('test_name') }}" required>
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.labs.result_value') }}
                            <input class="principal-input" name="result_value" maxlength="255" value="{{ old('result_value') }}">
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.labs.unit') }}
                            <input class="principal-input" name="unit" maxlength="64" value="{{ old('unit') }}">
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.labs.reference_range') }}
                            <input class="principal-input" name="reference_range" maxlength="255" value="{{ old('reference_range') }}">
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.labs.collected_at') }}
                            <input class="principal-input" type="datetime-local" name="collected_at" value="{{ old('collected_at') }}">
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.labs.resulted_at') }}
                            <input class="principal-input" type="datetime-local" name="resulted_at" value="{{ old('resulted_at', now()->format('Y-m-d\TH:i')) }}">
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.clinical.labs.source_name') }}
                            <input class="principal-input" name="source_name" maxlength="120" value="{{ old('source_name') }}">
                        </label>
                        <label class="grid gap-1 text-sm sm:col-span-2">
                            {{ __('lba.clinical.labs.notes') }}
                            <input class="principal-input" name="notes" maxlength="2000" value="{{ old('notes') }}">
                        </label>
                        <p class="text-xs text-slate-500 sm:col-span-3">{{ __('lba.clinical.labs.range_notice') }}</p>
                        <button class="principal-button principal-button-primary justify-self-start sm:col-span-3" type="submit">{{ __('lba.clinical.labs.add') }}</button>
                    </form>
                </details>
            @endif

            <ul class="divide-y divide-slate-100">
                @forelse ($encounter->labResults->sortBy('resulted_at') as $result)
                    <li class="py-3">
                        <p class="font-medium">{{ $result->test_name }}</p>
                        <p class="text-sm text-slate-600">
                            {{ $result->result_value ?: '—' }}@if ($result->unit) {{ $result->unit }} @endif
                            @if ($result->reference_range) · {{ __('lba.clinical.labs.reference_range') }}: {{ $result->reference_range }} @endif
                        </p>
                        <p class="text-xs text-slate-500">{{ $result->resulted_at?->translatedFormat('d M Y H:i') ?? '—' }}@if ($result->source_name) · {{ $result->source_name }} @endif</p>
                    </li>
                @empty
                    <li class="py-3 text-sm text-slate-500">{{ __('lba.clinical.labs.empty') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- ------------------------------------------------ LBA handoff --}}
        @if ($lbaAvailable)
            <section class="principal-card p-5">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-semibold text-navy-900">{{ __('lba.clinical.lba.linked') }}</h3>
                    @if ($canManage)
                        <form method="post" action="{{ route('clinic.tailadmin.patients.encounters.lba.store', [$patient, $encounter]) }}" class="flex flex-wrap items-center gap-2">
                            @csrf
                            <button class="principal-button principal-button-primary" type="submit">{{ __('lba.clinical.lba.start') }}</button>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="record_consent" value="1" @checked(old('record_consent') === '1')>
                                <span>{{ __('lba.exams.record_consent') }}</span>
                            </label>
                        </form>
                    @endif
                </div>

                <p class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('lba.clinical.lba.safety_notice') }}</p>

                <ul class="divide-y divide-slate-100">
                    @forelse ($lbaExaminations as $examination)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="font-medium">{{ $examination->captured_at?->translatedFormat('d M Y H:i') ?? '—' }}</p>
                                <p class="text-sm text-slate-600">
                                    {{ __('lba.exams.mode_'.$examination->mode) }} · {{ __('lba.exams.status') }}: {{ __('lba.exams.status_'.$examination->status) }}
                                </p>
                            </div>
                            <a class="principal-button" href="{{ route('clinic.tailadmin.examinations.show', $examination) }}">{{ __('lba.clinical.lba.open') }}</a>
                        </li>
                    @empty
                        <li class="py-3 text-sm text-slate-500">{{ __('lba.clinical.lba.empty') }}</li>
                    @endforelse
                </ul>
            </section>
        @endif
    </div>
@endsection
