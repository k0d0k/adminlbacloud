@extends('principal.layouts.app', ['title' => $patient->full_name, 'panel' => 'clinic'])

@php
    use Modules\Patients\Models\Patient;
    use Modules\Patients\Models\PatientAllergy;
    use Modules\Patients\Models\PatientCondition;
    use Modules\Patients\Models\PatientHealthHistory;
    use Modules\Patients\Models\PatientMedication;

    // Time-ordered inline trend line (SVG, no client dependency). Points are
    // already ordered by measurement time by the ClinicalSummary read model.
    $spark = function (array $values, string $stroke = '#0f766e'): string {
        $values = array_values(array_filter($values, static fn ($value) => $value !== null));
        if ($values === []) {
            return '';
        }
        $w = 300;
        $h = 80;
        $pad = 6;
        $min = min($values);
        $max = max($values);
        $span = max($max - $min, 1);
        $step = count($values) > 1 ? ($w - 2 * $pad) / (count($values) - 1) : 0;
        $points = [];
        foreach ($values as $i => $value) {
            $x = $pad + $i * $step;
            $y = $h - $pad - (($value - $min) / $span) * ($h - 2 * $pad);
            $points[] = round($x, 1).','.round($y, 1);
        }
        return '<svg viewBox="0 0 '.$w.' '.$h.'" class="h-20 w-full" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="'.$stroke.'" stroke-width="2" points="'.implode(' ', $points).'"/></svg>';
    };
@endphp

@section('content')
    <div class="grid gap-4">
        <section class="principal-card p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="wrap-break-word font-semibold text-navy-900">{{ $patient->full_name }}</h2>
                    <p class="text-sm text-slate-600">
                        {{ $patient->patient_number }}
                        · {{ $patient->age() !== null ? __('lba.clinical.age_years', ['age' => $patient->age()]) : __('lba.clinical.age_unknown') }}
                        @if ($patient->sex !== null) · {{ __('lba.patients.sex_'.$patient->sex) }} @endif
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-3 py-1 text-sm font-medium {{ $patient->isActive() ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                        {{ __('lba.patients.status_'.$patient->status) }}
                    </span>
                    @if ($canViewSina)
                        <a class="principal-button" href="{{ route('clinic.tailadmin.sina', ['patient_id' => $patient->getKey()]) }}">{{ __('lba.sina.chat_patient') }}</a>
                    @endif
                    @if ($canManage)
                        <a class="principal-button" href="{{ route('clinic.tailadmin.patients.edit', $patient) }}">{{ __('lba.patients.edit') }}</a>
                        @if ($patient->isActive())
                            <form method="post" action="{{ route('clinic.tailadmin.patients.archive', $patient) }}">
                                @csrf
                                <button class="principal-button" type="submit">{{ __('lba.patients.archive') }}</button>
                            </form>
                        @else
                            <form method="post" action="{{ route('clinic.tailadmin.patients.restore', $patient) }}">
                                @csrf
                                <button class="principal-button" type="submit">{{ __('lba.patients.restore') }}</button>
                            </form>
                        @endif
                    @endif
                    <a class="principal-button" href="{{ route('clinic.tailadmin.index', 'patients') }}">{{ __('lba.patients.back') }}</a>
                </div>
            </div>

            @if ($canReadClinical && $clinical !== null)
                @php($alerts = $clinical['alerts'])
                <div class="mt-3 grid gap-2 rounded-lg border border-slate-200 p-3 lg:grid-cols-4">
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
                    <div class="min-w-0">
                        <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ __('lba.clinical.latest_vitals') }}</p>
                        @if ($clinical['latestVital'] !== null)
                            <p class="text-sm font-medium">
                                {{ $clinical['latestVital']->bloodPressureLabel() ?? '—' }}
                                @if ($clinical['latestVital']->pulse !== null) · {{ $clinical['latestVital']->pulse }} bpm @endif
                            </p>
                            <p class="text-xs text-slate-500">{{ $clinical['latestVital']->measured_at?->translatedFormat('d M Y H:i') ?? '—' }}</p>
                        @else
                            <p class="text-sm text-slate-500">{{ __('lba.clinical.no_latest_vitals') }}</p>
                        @endif
                    </div>
                </div>
            @endif
        </section>

        <section class="principal-card grid gap-3 p-4 sm:grid-cols-2">
            <div>
                <h3 class="font-semibold text-navy-900">{{ __('lba.sina.credits_balance') }}</h3>
                <p class="text-2xl font-semibold text-brand-700">{{ number_format($sinaCredits) }}</p>
                @if ($canManageSinaCredits)
                    <p class="text-xs text-slate-500">{{ __('lba.sina.credits_grant_help') }}</p>
                @endif
            </div>
            @if ($canManageSinaCredits)
                <form method="post" action="{{ route('clinic.tailadmin.patients.sina-credits', $patient) }}" class="grid gap-3">
                    @csrf
                    <input type="hidden" name="grant_key" value="{{ $sinaGrantKey }}">
                    <h3 class="font-semibold text-navy-900">{{ __('lba.sina.credits_grant_title') }}</h3>
                    @error('credits')
                        <p class="text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                    @error('reason')
                        <p class="text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.sina.credits_amount') }}
                            <input class="principal-input" type="number" name="credits" min="1" max="100000000" required value="{{ old('credits') }}">
                        </label>
                        <label class="grid gap-1 text-sm">
                            {{ __('lba.sina.credits_reason') }}
                            <input class="principal-input" name="reason" maxlength="160" required value="{{ old('reason') }}">
                        </label>
                    </div>
                    <button class="principal-button principal-button-primary justify-self-start">{{ __('lba.sina.credits_grant') }}</button>
                </form>
            @endif
        </section>

        @if ($canManage)
            <section class="principal-card grid gap-3 p-4 sm:grid-cols-2">
                <div>
                    <h3 class="font-semibold text-navy-900">{{ __('lba.patients.portal_account') }}</h3>
                    <p class="text-sm text-slate-600">
                        @if ($patient->isLinked())
                            {{ __('lba.patients.portal_linked') }}
                        @else
                            {{ __('lba.patients.portal_not_linked') }}
                        @endif
                    </p>
                </div>
                @if ($patient->isLinked())
                    <form method="post" action="{{ route('clinic.tailadmin.patients.portal-password', $patient) }}" class="grid gap-3">
                        @csrf
                        <h3 class="font-semibold text-navy-900">{{ __('lba.patients.portal_password_reset_title') }}</h3>
                        @error('password')
                            <p class="text-sm text-rose-600">{{ $message }}</p>
                        @enderror
                        <p class="text-xs text-slate-500">{{ __('lba.patients.portal_password_reset_help') }}</p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="grid gap-1 text-sm">
                                {{ __('lba.patients.portal_password_new') }}
                                <input class="principal-input" type="password" name="password" minlength="8" maxlength="255" required autocomplete="new-password">
                            </label>
                            <label class="grid gap-1 text-sm">
                                {{ __('lba.patients.portal_password_confirm') }}
                                <input class="principal-input" type="password" name="password_confirmation" minlength="8" maxlength="255" required autocomplete="new-password">
                            </label>
                        </div>
                        <button class="principal-button principal-button-primary justify-self-start">{{ __('lba.patients.portal_password_reset_submit') }}</button>
                    </form>
                @endif
            </section>
        @endif

        @if ($canReadClinical && $clinical !== null)
            <nav class="flex flex-wrap gap-1" aria-label="{{ __('lba.clinical.heading') }}">
                @foreach (['summary', 'history', 'encounters', 'trends', 'lba'] as $tab)
                    <a href="{{ route('clinic.tailadmin.patients.show', ['patient' => $patient, 'tab' => $tab]) }}"
                        class="rounded-full px-3 py-1 text-sm font-medium {{ $activeTab === $tab ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-700' }}"
                        @if ($activeTab === $tab) aria-current="page" @endif>{{ __('lba.clinical.tabs.'.$tab) }}</a>
                @endforeach
            </nav>

            @unless ($canManageClinical)
                <p class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">{{ __('lba.clinical.read_only_notice') }}</p>
            @endunless

            @include('clinic.tailadmin.partials.patient-tab-'.$activeTab, ['patient' => $patient, 'clinical' => $clinical, 'spark' => $spark, 'lbaExaminations' => $lbaExaminations, 'canManageClinical' => $canManageClinical])
        @else
            <section class="principal-card">
                <div class="border-b border-slate-200 p-4">
                    <h3 class="font-semibold text-navy-900">{{ __('lba.patients.appointments.title') }}</h3>
                </div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($appointments as $appointment)
                        <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                            <div class="min-w-0">
                                <p class="font-medium">{{ $appointment->starts_at?->translatedFormat('d M Y H:i') ?? '—' }}</p>
                                <p class="text-sm text-slate-600">
                                    {{ __('lba.appointments.status_'.$appointment->status) }}
                                    @if ($appointment->branch) · {{ $appointment->branch->name }} @endif
                                </p>
                            </div>
                        </li>
                    @empty
                        <li class="p-8 text-center text-sm text-slate-500">{{ __('lba.patients.appointments.empty') }}</li>
                    @endforelse
                </ul>
            </section>
        @endif
    </div>
@endsection
