{{--
    Hasil Pemeriksaan & Tren — the measurement history over time (Phase 5).

    Blood pressure, weight and EACH glucose kind get their own chart. Fasting,
    random, post-meal glucose and HbA1c are different measurements, so plotting
    them on one axis would be clinically misleading; every chart also states its
    unit and every table states the time the value was taken.
--}}
@php
    $bloodPressure = $clinical['vitalSeries']['bloodPressure'];
    $weight = $clinical['vitalSeries']['weight'];
    $glucoseSeries = $clinical['glucoseSeries'];
    $systolic = array_map(static fn (array $point): int => $point['systolic'], $bloodPressure);
    $diastolic = array_map(static fn (array $point): int => $point['diastolic'], $bloodPressure);
    $weights = array_map(static fn (array $point): float => $point['value'], $weight);
@endphp

<div class="grid gap-4">
    <section class="principal-card p-5">
        <h3 class="mb-3 font-semibold text-navy-900">{{ __('lba.clinical.trends.blood_pressure') }}</h3>
        @if ($systolic !== [])
            <div class="grid gap-2">
                <div>{!! $spark($systolic, '#b91c1c') !!}</div>
                <div>{!! $spark($diastolic, '#1d4ed8') !!}</div>
            </div>
            <table class="mt-3 w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500">
                        <th class="py-1 font-medium">{{ __('lba.clinical.trends.time') }}</th>
                        <th class="py-1 font-medium">{{ __('lba.clinical.vitals.blood_pressure') }} (mmHg)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (array_slice($bloodPressure, -10) as $point)
                        <tr class="border-t border-slate-100">
                            <td class="py-1">{{ $point['at']->translatedFormat('d M Y H:i') }}</td>
                            <td class="py-1 font-medium">{{ $point['systolic'] }}/{{ $point['diastolic'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="text-sm text-slate-500">{{ __('lba.clinical.trends.no_data') }}</p>
        @endif
    </section>

    <section class="principal-card p-5">
        <h3 class="mb-3 font-semibold text-navy-900">{{ __('lba.clinical.trends.weight') }}</h3>
        @if ($weights !== [])
            <div>{!! $spark($weights) !!}</div>
            <table class="mt-3 w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500">
                        <th class="py-1 font-medium">{{ __('lba.clinical.trends.time') }}</th>
                        <th class="py-1 font-medium">{{ __('lba.clinical.trends.value') }} (kg)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (array_slice($weight, -10) as $point)
                        <tr class="border-t border-slate-100">
                            <td class="py-1">{{ $point['at']->translatedFormat('d M Y H:i') }}</td>
                            <td class="py-1 font-medium">{{ $point['value'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="text-sm text-slate-500">{{ __('lba.clinical.trends.no_data') }}</p>
        @endif
    </section>

    <section class="principal-card p-5">
        <h3 class="mb-1 font-semibold text-navy-900">{{ __('lba.clinical.trends.glucose') }}</h3>
        <p class="mb-3 text-sm text-slate-500">{{ __('lba.clinical.trends.glucose_per_kind') }}</p>
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($glucoseSeries as $kind => $series)
                <div class="rounded-lg border border-slate-200 p-3">
                    <h4 class="mb-2 text-sm font-medium text-navy-900">
                        {{ __('lba.clinical.glucose.kind_'.$kind) }}
                        <span class="text-slate-500">({{ $series['unit'] }})</span>
                    </h4>
                    @php($values = array_map(static fn (array $point): float => $point['value'], $series['points']))
                    @if ($values !== [])
                        <div>{!! $spark($values) !!}</div>
                        <table class="mt-2 w-full text-sm">
                            <tbody>
                                @foreach (array_slice($series['points'], -5) as $point)
                                    <tr class="border-t border-slate-100">
                                        <td class="py-1 text-slate-500">{{ $point['at']->translatedFormat('d M Y H:i') }}</td>
                                        <td class="py-1 text-right font-medium">{{ $point['value'] }} {{ $series['unit'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="text-sm text-slate-500">{{ __('lba.clinical.trends.no_data') }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <section class="principal-card p-5">
        <h3 class="mb-1 font-semibold text-navy-900">{{ __('lba.clinical.labs.title') }}</h3>
        <p class="mb-3 text-sm text-slate-500">{{ __('lba.clinical.labs.range_notice') }}</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500">
                        <th class="py-1 font-medium">{{ __('lba.clinical.labs.test_name') }}</th>
                        <th class="py-1 font-medium">{{ __('lba.clinical.labs.result_value') }}</th>
                        <th class="py-1 font-medium">{{ __('lba.clinical.labs.unit') }}</th>
                        <th class="py-1 font-medium">{{ __('lba.clinical.labs.reference_range') }}</th>
                        <th class="py-1 font-medium">{{ __('lba.clinical.labs.resulted_at') }}</th>
                        <th class="py-1 font-medium">{{ __('lba.clinical.labs.source_name') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($clinical['labs'] as $lab)
                        <tr class="border-t border-slate-100">
                            <td class="wrap-break-word py-1 font-medium">{{ $lab->test_name }}</td>
                            <td class="py-1">{{ $lab->result_value ?? __('lba.clinical.not_assessed') }}</td>
                            <td class="py-1">{{ $lab->unit ?? '—' }}</td>
                            <td class="py-1">{{ $lab->reference_range ?? '—' }}</td>
                            <td class="py-1">{{ $lab->resulted_at?->translatedFormat('d M Y H:i') ?? '—' }}</td>
                            <td class="py-1">{{ $lab->source_name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-3 text-slate-500">{{ __('lba.clinical.labs.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
