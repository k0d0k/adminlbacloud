{{--
    Riwayat Kesehatan & Obat — the longitudinal health profile (Phase 2).

    Four multi-entry sections (conditions, allergies, medications and supporting
    history). Every entry is written through the authorized clinical service, so
    it starts UNVERIFIED with an explicit `source`; a clinician promotes it with
    "Verifikasi" and the reviewer and time are recorded. Nothing a patient
    reported is presented as a diagnosis, and the overall allergy answer is a
    separate, explicit three-valued clinical statement.
--}}
@php
    use Modules\Patients\Models\Patient;
    use Modules\Patients\Models\PatientAllergy;
    use Modules\Patients\Models\PatientCondition;
    use Modules\Patients\Models\PatientHealthHistory;
    use Modules\Patients\Models\PatientMedication;

    // Exposed through a model that uses the trait: PHP 8.5 forbids reading a
    // trait constant directly off the trait.
    $sources = PatientCondition::SOURCES;
    $severities = PatientAllergy::SEVERITIES;
    $allergyCategories = PatientAllergy::CATEGORIES;
    $conditionStatuses = PatientCondition::STATUSES;
    $medicationStatuses = PatientMedication::STATUSES;
    $historyCategories = PatientHealthHistory::CATEGORIES;
    $allergyStatus = $patient->allergy_status ?? Patient::ALLERGY_STATUS_UNKNOWN;
@endphp

<div class="grid gap-4">
    {{-- ---------------------------------------------------- Allergy status --}}
    <section class="principal-card p-5">
        <h3 class="mb-2 font-semibold text-navy-900">{{ __('lba.clinical.allergy_status.label') }}</h3>
        <p class="mb-3 text-sm text-slate-600">{{ __('lba.clinical.allergy_status.notice') }}</p>
        @if ($canManageClinical)
            <form method="post" action="{{ route('clinic.tailadmin.patients.clinical.allergy_status', $patient) }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <label class="grid gap-1 text-sm">
                    {{ __('lba.clinical.allergy_status.label') }}
                    <select name="allergy_status" class="principal-input">
                        @foreach (Patient::ALLERGY_STATUSES as $status)
                            <option value="{{ $status }}" @selected($allergyStatus === $status)>{{ __('lba.clinical.allergy_status.'.$status) }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="principal-button principal-button-primary">{{ __('lba.clinical.allergy_status.save') }}</button>
            </form>
        @else
            <p class="font-medium">{{ __('lba.clinical.allergy_status.'.$allergyStatus) }}</p>
        @endif
    </section>

    {{-- -------------------------------------------------------- Conditions --}}
    <section class="principal-card p-5">
        <h3 class="mb-3 font-semibold text-navy-900">{{ __('lba.clinical.conditions.title') }}</h3>

        @if ($canManageClinical)
            <details class="mb-4 rounded-lg border border-slate-200 p-3">
                <summary class="cursor-pointer text-sm font-medium text-brand-700">{{ __('lba.clinical.conditions.add') }}</summary>
                <form method="post" action="{{ route('clinic.tailadmin.patients.clinical.entries.store', ['patient' => $patient, 'type' => 'conditions']) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                    @csrf
                    <label class="grid gap-1 text-sm sm:col-span-2">
                        {{ __('lba.clinical.conditions.name') }}
                        <input class="principal-input" name="name" maxlength="255" required>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.conditions.icd10') }}
                        <input class="principal-input" name="icd10_code" maxlength="16">
                        <span class="text-xs text-slate-500">{{ __('lba.clinical.conditions.icd10_hint') }}</span>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.conditions.onset') }}
                        <input class="principal-input" type="date" name="onset_date">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.conditions.status') }}
                        <select name="status" class="principal-input">
                            @foreach ($conditionStatuses as $status)
                                <option value="{{ $status }}">{{ __('lba.clinical.conditions.status_'.$status) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.source') }}
                        <select name="source" class="principal-input">
                            @foreach ($sources as $source)
                                <option value="{{ $source }}">{{ __('lba.clinical.source_'.$source) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm sm:col-span-2">
                        {{ __('lba.clinical.conditions.notes') }}
                        <textarea class="principal-input" name="notes" rows="2" maxlength="2000"></textarea>
                    </label>
                    <button class="principal-button principal-button-primary justify-self-start">{{ __('lba.clinical.add') }}</button>
                </form>
            </details>
        @endif

        <ul class="divide-y divide-slate-100">
            @forelse ($clinical['conditions'] as $condition)
                <li class="grid gap-2 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="min-w-0">
                            <p class="wrap-break-word font-medium">
                                {{ $condition->name }}@if ($condition->icd10_code) · {{ $condition->icd10_code }} @endif
                                <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ __('lba.clinical.conditions.status_'.$condition->status) }}</span>
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ __('lba.clinical.source') }}: {{ __('lba.clinical.source_'.$condition->source) }}
                                · {{ $condition->isVerified() ? __('lba.clinical.verified') : __('lba.clinical.unverified') }}
                                @if ($condition->onset_date) · {{ __('lba.clinical.conditions.onset') }}: {{ $condition->onset_date->translatedFormat('d M Y') }} @endif
                            </p>
                        </div>
                        @if ($canManageClinical && $condition->isUnverified())
                            <form method="post" action="{{ route('clinic.tailadmin.patients.clinical.entries.verify', ['patient' => $patient, 'type' => 'conditions', 'entry' => $condition]) }}">
                                @csrf
                                <button class="principal-button">{{ __('lba.clinical.verify') }}</button>
                            </form>
                        @endif
                    </div>
                    @if ($canManageClinical)
                        <details class="text-sm">
                            <summary class="cursor-pointer text-brand-700">{{ __('lba.clinical.edit') }}</summary>
                            <form method="post" action="{{ route('clinic.tailadmin.patients.clinical.entries.update', ['patient' => $patient, 'type' => 'conditions', 'entry' => $condition]) }}" class="mt-2 grid gap-3 sm:grid-cols-2">
                                @csrf
                                @method('put')
                                <label class="grid gap-1 text-sm sm:col-span-2">
                                    {{ __('lba.clinical.conditions.name') }}
                                    <input class="principal-input" name="name" maxlength="255" value="{{ $condition->name }}" required>
                                </label>
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.conditions.icd10') }}
                                    <input class="principal-input" name="icd10_code" maxlength="16" value="{{ $condition->icd10_code }}">
                                </label>
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.conditions.onset') }}
                                    <input class="principal-input" type="date" name="onset_date" value="{{ $condition->onset_date?->toDateString() }}">
                                </label>
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.conditions.status') }}
                                    <select name="status" class="principal-input">
                                        @foreach ($conditionStatuses as $status)
                                            <option value="{{ $status }}" @selected($condition->status === $status)>{{ __('lba.clinical.conditions.status_'.$status) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.source') }}
                                    <select name="source" class="principal-input">
                                        @foreach ($sources as $source)
                                            <option value="{{ $source }}" @selected($condition->source === $source)>{{ __('lba.clinical.source_'.$source) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="grid gap-1 text-sm sm:col-span-2">
                                    {{ __('lba.clinical.conditions.notes') }}
                                    <textarea class="principal-input" name="notes" rows="2" maxlength="2000">{{ $condition->notes }}</textarea>
                                </label>
                                <button class="principal-button principal-button-primary justify-self-start">{{ __('lba.clinical.update') }}</button>
                            </form>
                        </details>
                    @endif
                </li>
            @empty
                <li class="py-3 text-sm text-slate-500">{{ __('lba.clinical.empty') }}</li>
            @endforelse
        </ul>
    </section>

    {{-- --------------------------------------------------------- Allergies --}}
    <section class="principal-card p-5">
        <h3 class="mb-3 font-semibold text-navy-900">{{ __('lba.clinical.allergies.title') }}</h3>

        @if ($canManageClinical)
            <details class="mb-4 rounded-lg border border-slate-200 p-3">
                <summary class="cursor-pointer text-sm font-medium text-brand-700">{{ __('lba.clinical.allergies.add') }}</summary>
                <form method="post" action="{{ route('clinic.tailadmin.patients.clinical.entries.store', ['patient' => $patient, 'type' => 'allergies']) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                    @csrf
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.allergies.substance') }}
                        <input class="principal-input" name="substance" maxlength="255" required>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.allergies.category') }}
                        <select name="category" class="principal-input">
                            @foreach ($allergyCategories as $category)
                                <option value="{{ $category }}">{{ __('lba.clinical.allergies.category_'.$category) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.allergies.reaction') }}
                        <input class="principal-input" name="reaction" maxlength="255">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.allergies.severity') }}
                        <select name="severity" class="principal-input">
                            @foreach ($severities as $severity)
                                <option value="{{ $severity }}">{{ __('lba.clinical.allergies.severity_'.$severity) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.allergies.occurred_on') }}
                        <input class="principal-input" type="date" name="occurred_on">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.source') }}
                        <select name="source" class="principal-input">
                            @foreach ($sources as $source)
                                <option value="{{ $source }}">{{ __('lba.clinical.source_'.$source) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" checked>
                        {{ __('lba.clinical.allergies.active') }}
                    </label>
                    <button class="principal-button principal-button-primary justify-self-start">{{ __('lba.clinical.add') }}</button>
                </form>
            </details>
        @endif

        <ul class="divide-y divide-slate-100">
            @forelse ($clinical['allergies'] as $allergy)
                <li class="grid gap-2 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="min-w-0">
                            <p class="wrap-break-word font-medium text-red-700">
                                {{ $allergy->substance }}
                                @if ($allergy->reaction) · {{ $allergy->reaction }} @endif
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ __('lba.clinical.allergies.category_'.$allergy->category) }}
                                · {{ __('lba.clinical.allergies.severity') }}: {{ __('lba.clinical.allergies.severity_'.$allergy->severity) }}
                                · {{ __('lba.clinical.source') }}: {{ __('lba.clinical.source_'.$allergy->source) }}
                                · {{ $allergy->isVerified() ? __('lba.clinical.verified') : __('lba.clinical.unverified') }}
                                @unless ($allergy->is_active) · {{ __('lba.clinical.allergies.active') }}: — @endunless
                            </p>
                        </div>
                        @if ($canManageClinical && $allergy->isUnverified())
                            <form method="post" action="{{ route('clinic.tailadmin.patients.clinical.entries.verify', ['patient' => $patient, 'type' => 'allergies', 'entry' => $allergy]) }}">
                                @csrf
                                <button class="principal-button">{{ __('lba.clinical.verify') }}</button>
                            </form>
                        @endif
                    </div>
                    @if ($canManageClinical)
                        <details class="text-sm">
                            <summary class="cursor-pointer text-brand-700">{{ __('lba.clinical.edit') }}</summary>
                            <form method="post" action="{{ route('clinic.tailadmin.patients.clinical.entries.update', ['patient' => $patient, 'type' => 'allergies', 'entry' => $allergy]) }}" class="mt-2 grid gap-3 sm:grid-cols-2">
                                @csrf
                                @method('put')
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.allergies.substance') }}
                                    <input class="principal-input" name="substance" maxlength="255" value="{{ $allergy->substance }}" required>
                                </label>
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.allergies.category') }}
                                    <select name="category" class="principal-input">
                                        @foreach ($allergyCategories as $category)
                                            <option value="{{ $category }}" @selected($allergy->category === $category)>{{ __('lba.clinical.allergies.category_'.$category) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.allergies.reaction') }}
                                    <input class="principal-input" name="reaction" maxlength="255" value="{{ $allergy->reaction }}">
                                </label>
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.allergies.severity') }}
                                    <select name="severity" class="principal-input">
                                        @foreach ($severities as $severity)
                                            <option value="{{ $severity }}" @selected($allergy->severity === $severity)>{{ __('lba.clinical.allergies.severity_'.$severity) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.allergies.occurred_on') }}
                                    <input class="principal-input" type="date" name="occurred_on" value="{{ $allergy->occurred_on?->toDateString() }}">
                                </label>
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="hidden" name="is_active" value="0">
                                    <input type="checkbox" name="is_active" value="1" @checked($allergy->is_active)>
                                    {{ __('lba.clinical.allergies.active') }}
                                </label>
                                <button class="principal-button principal-button-primary justify-self-start">{{ __('lba.clinical.update') }}</button>
                            </form>
                        </details>
                    @endif
                </li>
            @empty
                <li class="py-3 text-sm text-slate-500">{{ __('lba.clinical.empty') }}</li>
            @endforelse
        </ul>
    </section>

    {{-- ------------------------------------------------------- Medications --}}
    <section class="principal-card p-5">
        <h3 class="mb-2 font-semibold text-navy-900">{{ __('lba.clinical.medications.title') }}</h3>
        <p class="mb-3 text-sm text-slate-500">{{ __('lba.clinical.medications.notice') }}</p>

        @if ($canManageClinical)
            <details class="mb-4 rounded-lg border border-slate-200 p-3">
                <summary class="cursor-pointer text-sm font-medium text-brand-700">{{ __('lba.clinical.medications.add') }}</summary>
                <form method="post" action="{{ route('clinic.tailadmin.patients.clinical.entries.store', ['patient' => $patient, 'type' => 'medications']) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                    @csrf
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.medications.name') }}
                        <input class="principal-input" name="name" maxlength="255" required>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.medications.strength') }}
                        <input class="principal-input" name="strength" maxlength="120">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.medications.dose') }}
                        <input class="principal-input" name="dose" maxlength="120">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.medications.route') }}
                        <input class="principal-input" name="route" maxlength="120">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.medications.frequency') }}
                        <input class="principal-input" name="frequency" maxlength="120">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.medications.indication') }}
                        <input class="principal-input" name="indication" maxlength="255">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.medications.started_on') }}
                        <input class="principal-input" type="date" name="started_on">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.medications.ended_on') }}
                        <input class="principal-input" type="date" name="ended_on">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.medications.status') }}
                        <select name="status" class="principal-input">
                            @foreach ($medicationStatuses as $status)
                                <option value="{{ $status }}">{{ __('lba.clinical.medications.status_'.$status) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.source') }}
                        <select name="source" class="principal-input">
                            @foreach ($sources as $source)
                                <option value="{{ $source }}">{{ __('lba.clinical.source_'.$source) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="principal-button principal-button-primary justify-self-start">{{ __('lba.clinical.add') }}</button>
                </form>
            </details>
        @endif

        <ul class="divide-y divide-slate-100">
            @forelse ($clinical['medications'] as $medication)
                <li class="grid gap-2 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="min-w-0">
                            <p class="wrap-break-word font-medium">
                                {{ $medication->name }}
                                @if ($medication->strength) {{ $medication->strength }} @endif
                                <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ __('lba.clinical.medications.status_'.$medication->status) }}</span>
                            </p>
                            <p class="text-xs text-slate-500">
                                @if ($medication->dose) {{ $medication->dose }} · @endif
                                @if ($medication->frequency) {{ $medication->frequency }} · @endif
                                {{ __('lba.clinical.source') }}: {{ __('lba.clinical.source_'.$medication->source) }}
                                · {{ $medication->isVerified() ? __('lba.clinical.verified') : __('lba.clinical.unverified') }}
                            </p>
                        </div>
                        @if ($canManageClinical && $medication->isUnverified())
                            <form method="post" action="{{ route('clinic.tailadmin.patients.clinical.entries.verify', ['patient' => $patient, 'type' => 'medications', 'entry' => $medication]) }}">
                                @csrf
                                <button class="principal-button">{{ __('lba.clinical.verify') }}</button>
                            </form>
                        @endif
                    </div>
                    @if ($canManageClinical)
                        <details class="text-sm">
                            <summary class="cursor-pointer text-brand-700">{{ __('lba.clinical.edit') }}</summary>
                            <form method="post" action="{{ route('clinic.tailadmin.patients.clinical.entries.update', ['patient' => $patient, 'type' => 'medications', 'entry' => $medication]) }}" class="mt-2 grid gap-3 sm:grid-cols-2">
                                @csrf
                                @method('put')
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.medications.name') }}
                                    <input class="principal-input" name="name" maxlength="255" value="{{ $medication->name }}" required>
                                </label>
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.medications.strength') }}
                                    <input class="principal-input" name="strength" maxlength="120" value="{{ $medication->strength }}">
                                </label>
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.medications.dose') }}
                                    <input class="principal-input" name="dose" maxlength="120" value="{{ $medication->dose }}">
                                </label>
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.medications.frequency') }}
                                    <input class="principal-input" name="frequency" maxlength="120" value="{{ $medication->frequency }}">
                                </label>
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.medications.started_on') }}
                                    <input class="principal-input" type="date" name="started_on" value="{{ $medication->started_on?->toDateString() }}">
                                </label>
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.medications.ended_on') }}
                                    <input class="principal-input" type="date" name="ended_on" value="{{ $medication->ended_on?->toDateString() }}">
                                </label>
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.medications.status') }}
                                    <select name="status" class="principal-input">
                                        @foreach ($medicationStatuses as $status)
                                            <option value="{{ $status }}" @selected($medication->status === $status)>{{ __('lba.clinical.medications.status_'.$status) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <button class="principal-button principal-button-primary justify-self-start">{{ __('lba.clinical.update') }}</button>
                            </form>
                        </details>
                    @endif
                </li>
            @empty
                <li class="py-3 text-sm text-slate-500">{{ __('lba.clinical.empty') }}</li>
            @endforelse
        </ul>
    </section>

    {{-- ---------------------------------------------------- Other history --}}
    <section class="principal-card p-5">
        <h3 class="mb-3 font-semibold text-navy-900">{{ __('lba.clinical.histories.title') }}</h3>

        @if ($canManageClinical)
            <details class="mb-4 rounded-lg border border-slate-200 p-3">
                <summary class="cursor-pointer text-sm font-medium text-brand-700">{{ __('lba.clinical.histories.add') }}</summary>
                <form method="post" action="{{ route('clinic.tailadmin.patients.clinical.entries.store', ['patient' => $patient, 'type' => 'histories']) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                    @csrf
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.histories.category') }}
                        <select name="category" class="principal-input">
                            @foreach ($historyCategories as $category)
                                <option value="{{ $category }}">{{ __('lba.clinical.histories.category_'.$category) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.histories.entry_title') }}
                        <input class="principal-input" name="title" maxlength="255" required>
                    </label>
                    <label class="grid gap-1 text-sm sm:col-span-2">
                        {{ __('lba.clinical.histories.detail') }}
                        <textarea class="principal-input" name="detail" rows="2" maxlength="2000"></textarea>
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.histories.occurred_on') }}
                        <input class="principal-input" type="date" name="occurred_on">
                    </label>
                    <label class="grid gap-1 text-sm">
                        {{ __('lba.clinical.source') }}
                        <select name="source" class="principal-input">
                            @foreach ($sources as $source)
                                <option value="{{ $source }}">{{ __('lba.clinical.source_'.$source) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="principal-button principal-button-primary justify-self-start">{{ __('lba.clinical.add') }}</button>
                </form>
            </details>
        @endif

        <ul class="divide-y divide-slate-100">
            @forelse ($clinical['histories'] as $history)
                <li class="grid gap-2 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="min-w-0">
                            <p class="wrap-break-word font-medium">
                                <span class="mr-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ __('lba.clinical.histories.category_'.$history->category) }}</span>
                                {{ $history->title }}
                            </p>
                            @if ($history->detail)
                                <p class="wrap-break-word text-sm text-slate-600">{{ $history->detail }}</p>
                            @endif
                            <p class="text-xs text-slate-500">
                                {{ __('lba.clinical.source') }}: {{ __('lba.clinical.source_'.$history->source) }}
                                · {{ $history->isVerified() ? __('lba.clinical.verified') : __('lba.clinical.unverified') }}
                                @if ($history->occurred_on) · {{ $history->occurred_on->translatedFormat('d M Y') }} @endif
                            </p>
                        </div>
                        @if ($canManageClinical && $history->isUnverified())
                            <form method="post" action="{{ route('clinic.tailadmin.patients.clinical.entries.verify', ['patient' => $patient, 'type' => 'histories', 'entry' => $history]) }}">
                                @csrf
                                <button class="principal-button">{{ __('lba.clinical.verify') }}</button>
                            </form>
                        @endif
                    </div>
                    @if ($canManageClinical)
                        <details class="text-sm">
                            <summary class="cursor-pointer text-brand-700">{{ __('lba.clinical.edit') }}</summary>
                            <form method="post" action="{{ route('clinic.tailadmin.patients.clinical.entries.update', ['patient' => $patient, 'type' => 'histories', 'entry' => $history]) }}" class="mt-2 grid gap-3 sm:grid-cols-2">
                                @csrf
                                @method('put')
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.histories.category') }}
                                    <select name="category" class="principal-input">
                                        @foreach ($historyCategories as $category)
                                            <option value="{{ $category }}" @selected($history->category === $category)>{{ __('lba.clinical.histories.category_'.$category) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.histories.entry_title') }}
                                    <input class="principal-input" name="title" maxlength="255" value="{{ $history->title }}" required>
                                </label>
                                <label class="grid gap-1 text-sm sm:col-span-2">
                                    {{ __('lba.clinical.histories.detail') }}
                                    <textarea class="principal-input" name="detail" rows="2" maxlength="2000">{{ $history->detail }}</textarea>
                                </label>
                                <label class="grid gap-1 text-sm">
                                    {{ __('lba.clinical.histories.occurred_on') }}
                                    <input class="principal-input" type="date" name="occurred_on" value="{{ $history->occurred_on?->toDateString() }}">
                                </label>
                                <button class="principal-button principal-button-primary justify-self-start">{{ __('lba.clinical.update') }}</button>
                            </form>
                        </details>
                    @endif
                </li>
            @empty
                <li class="py-3 text-sm text-slate-500">{{ __('lba.clinical.empty') }}</li>
            @endforelse
        </ul>
    </section>
</div>
