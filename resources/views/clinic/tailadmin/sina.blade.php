@extends('principal.layouts.app', ['title' => __('lba.sina.title'), 'panel' => 'clinic'])

@section('content')
    <section class="principal-card">
        <div class="border-b border-slate-200 p-4">
            <h2 class="font-semibold text-navy-900">
                {{ $patientFilter ? __('lba.sina.patient_filter').' · '.$patientFilter->full_name : __('lba.sina.title') }}
            </h2>
            <p class="text-sm text-slate-600">{{ __('lba.sina.subtitle') }}</p>
            @if ($patientFilter)
                <a class="principal-button mt-3" href="{{ route('clinic.tailadmin.sina') }}">{{ __('lba.sina.nav') }}</a>
            @endif
        </div>
        <ul class="divide-y divide-slate-100">
            @forelse ($sessions as $session)
                <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <p class="wrap-break-word font-semibold">{{ $session->patient?->full_name ?? '—' }}</p>
                        <p class="text-sm text-slate-600">
                            {{ $session->scheduled_at?->translatedFormat('d M Y H:i') ?? '—' }}
                            @if ($session->branch) · {{ $session->branch->name }} @endif
                            · {{ __('lba.consultations.status_'.$session->status) }}
                        </p>
                    </div>
                    <a class="principal-button" href="{{ route('clinic.tailadmin.consultations.show', $session) }}">{{ __('lba.sina.open') }}</a>
                </li>
            @empty
                <li class="p-8 text-center text-sm text-slate-500">
                    {{ $patientFilter ? __('lba.sina.patient_has_no_session') : __('lba.sina.empty') }}
                </li>
            @endforelse
        </ul>
        @if ($sessions->hasPages())
            <div class="border-t border-slate-200 p-4">{{ $sessions->links() }}</div>
        @endif
    </section>
@endsection
