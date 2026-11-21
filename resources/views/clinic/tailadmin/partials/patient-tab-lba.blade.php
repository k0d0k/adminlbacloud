{{--
    Pemeriksaan LBA — the patient's LBA examination history (Phase 4).

    This tab never invents a second examination workflow: it lists the LBA cases
    the existing module already owns and links into them. The safety notice is
    permanent because LBA is visual observation, not a validated diagnostic
    method, and the platform must never present it as one.
--}}
<div class="grid gap-4">
    <section class="principal-card p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="font-semibold text-navy-900">{{ __('lba.clinical.lba.history') }}</h3>
            @if ($canManageClinical)
                <a class="principal-button" href="{{ route('clinic.tailadmin.intake.create', ['intake' => 'examinations', 'search' => $patient->patient_number]) }}">{{ __('lba.clinical.lba.start') }}</a>
            @endif
        </div>

        <p class="mt-2 mb-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('lba.clinical.lba.safety_notice') }}</p>

        <ul class="divide-y divide-slate-100">
            @forelse ($lbaExaminations as $examination)
                <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <p class="font-medium">
                            {{ $examination->captured_at?->translatedFormat('d M Y H:i') ?? '—' }}
                            <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ __('lba.exams.mode_'.$examination->mode) }}</span>
                        </p>
                        <p class="text-sm text-slate-600">
                            {{ __('lba.exams.status') }}: {{ __('lba.exams.status_'.$examination->status) }}
                            @if ($examination->clinical_encounter_id) · {{ __('lba.clinical.lba.context') }} @endif
                        </p>
                    </div>
                    <a class="principal-button" href="{{ route('clinic.tailadmin.examinations.show', $examination) }}">{{ __('lba.clinical.lba.open') }}</a>
                </li>
            @empty
                <li class="py-3 text-sm text-slate-500">{{ __('lba.clinical.lba.empty') }}</li>
            @endforelse
        </ul>
    </section>
</div>
