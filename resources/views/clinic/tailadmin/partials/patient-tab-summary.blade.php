{{--
    Ringkasan Pasien — identity, medical alerts and the latest measurements.

    Read-only by design: this tab is the "who is this patient and what must I
    not miss" view a clinician opens before starting an encounter. A missing
    value renders as an explicit "not recorded"/"not assessed" phrase — never
    as a number or an empty cell that could be read as a real zero.
--}}
@php
    use Modules\Patients\Models\Patient;

    $allergyStatus = $patient->allergy_status ?? Patient::ALLERGY_STATUS_UNKNOWN;
    $identity = [
        __('lba.patients.number') => $patient->patient_number,
        __('lba.clinical.medical_number') => $patient->patient_number,
        __('lba.patients.date_of_birth') => $patient->date_of_birth?->translatedFormat('d M Y'),
        __('lba.clinical.age') => $patient->age() !== null ? __('lba.clinical.age_years', ['age' => $patient->age()]) : __('lba.clinical.age_unknown'),
        __('lba.patients.sex') => $patient->sex !== null ? __('lba.patients.sex_'.$patient->sex) : __('lba.clinical.unknown'),
        __('lba.patients.phone') => $patient->phone,
        __('lba.patients.email') => $patient->email,
        __('lba.patients.address') => $patient->address,
        __('lba.patients.city') => $patient->city,
        __('lba.patients.region') => $patient->region,
        __('lba.patients.registration_branch') => $patient->registeredBranch?->name,
        __('lba.patients.registration_date') => $patient->registered_at?->translatedFormat('d M Y H:i'),
        __('lba.patients.status') => __('lba.patients.status_'.$patient->status),
    ];
@endphp

<div class="grid gap-4">
    <section class="principal-card p-5">
        <h3 class="mb-4 font-semibold text-navy-900">{{ __('lba.patients.section_identity') }}</h3>
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($identity as $label => $value)
                <div class="min-w-0">
                    <dt class="text-sm text-slate-500">{{ $label }}</dt>
                    <dd class="wrap-break-word font-medium">{{ filled($value) ? $value : '—' }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="principal-card p-5">
        <h3 class="mb-3 font-semibold text-navy-900">{{ __('lba.clinical.alerts.title') }}</h3>
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="min-w-0">
                <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ __('lba.clinical.allergy_status.label') }}</p>
                @php($hasAllergyAlert = $allergyStatus === Patient::ALLERGY_STATUS_HAS_ALLERGIES)
                <p class="mt-1 inline-flex rounded-full px-3 py-1 text-sm font-medium {{ $hasAllergyAlert ? 'bg-red-100 text-red-700' : ($allergyStatus === Patient::ALLERGY_STATUS_NONE_KNOWN ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600') }}">
                    {{ __('lba.clinical.allergy_status.'.$allergyStatus) }}
                </p>
                @if ($patient->allergy_status_recorded_at !== null)
                    <p class="mt-1 text-xs text-slate-500">{{ __('lba.clinical.allergy_status.recorded_at') }}: {{ $patient->allergy_status_recorded_at->translatedFormat('d M Y H:i') }}</p>
                @endif
                <p class="mt-2 text-sm text-slate-600">{{ __('lba.clinical.allergy_status.notice') }}</p>
            </div>
            <div class="min-w-0">
                <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ __('lba.clinical.alerts.conditions') }}</p>
                <ul class="mt-1 grid gap-1">
                    @forelse ($clinical['alerts']['conditions'] as $condition)
                        <li class="wrap-break-word text-sm font-medium">{{ $condition->name }}@if ($condition->icd10_code) · {{ $condition->icd10_code }} @endif</li>
                    @empty
                        <li class="text-sm text-slate-500">—</li>
                    @endforelse
                </ul>
            </div>
            <div class="min-w-0">
                <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ __('lba.clinical.alerts.medications') }}</p>
                <ul class="mt-1 grid gap-1">
                    @forelse ($clinical['alerts']['medications'] as $medication)
                        <li class="wrap-break-word text-sm font-medium">{{ $medication->name }}@if ($medication->dose) · {{ $medication->dose }} @endif</li>
                    @empty
                        <li class="text-sm text-slate-500">—</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </section>

    <section class="principal-card p-5">
        <h3 class="mb-3 font-semibold text-navy-900">{{ __('lba.clinical.latest_vitals') }}</h3>
        @if ($clinical['latestVital'] !== null)
            @php($vital = $clinical['latestVital'])
            <dl class="grid gap-4 sm:grid-cols-3 lg:grid-cols-4">
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.clinical.vitals.blood_pressure') }}</dt>
                    <dd class="font-medium">{{ $vital->bloodPressureLabel() ?? __('lba.clinical.vitals.not_measured') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.clinical.vitals.pulse') }}</dt>
                    <dd class="font-medium">{{ $vital->pulse ?? __('lba.clinical.vitals.not_measured') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.clinical.vitals.weight') }}</dt>
                    <dd class="font-medium">{{ $vital->weight_kg ?? __('lba.clinical.vitals.not_measured') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-500">{{ __('lba.clinical.vitals.bmi') }}</dt>
                    <dd class="font-medium">{{ $vital->bmi ?? __('lba.clinical.vitals.not_measured') }}</dd>
                </div>
            </dl>
            <p class="mt-2 text-xs text-slate-500">{{ $vital->measured_at?->translatedFormat('d M Y H:i') ?? '—' }}</p>
        @else
            <p class="text-sm text-slate-500">{{ __('lba.clinical.no_latest_vitals') }}</p>
        @endif
    </section>
</div>
