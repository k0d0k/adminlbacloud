<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $tenant->name }}</title>
    <meta name="description" content="{{ $tenant->name }} — {{ __('site.default_clinic_description') }}">
    <meta name="theme-color" content="#14263D">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ route('tenant.frontpage', $tenant->slug) }}">

    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $tenant->name }}">
    <meta property="og:title" content="{{ $tenant->name }}">
    <meta property="og:description" content="{{ $tenant->name }} — {{ __('site.default_clinic_description') }}">
    <meta property="og:url" content="{{ route('tenant.frontpage', $tenant->slug) }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $tenant->name }}">
    <meta name="twitter:description" content="{{ $tenant->name }} — {{ __('site.default_clinic_description') }}">

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="lba-landing">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded-lg focus:bg-lba-navy focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white">
        {{ __('site.skip_to_content') }}
    </a>

    <header class="sticky top-0 z-50 border-b border-lba-line bg-white/85 backdrop-blur">
        <div class="lba-container flex h-16 items-center justify-between gap-4">
            <a href="{{ route('tenant.frontpage', $tenant->slug) }}" class="lba-brand" aria-label="{{ $tenant->name }} — {{ __('site.home') }}">
                <span class="lba-brand__mark" aria-hidden="true">
                    <svg viewBox="0 0 32 32" fill="none">
                        <path d="M16 3 27 9.5v13L16 29 5 22.5v-13L16 3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                        <circle cx="16" cy="16" r="4.5" fill="currentColor" />
                    </svg>
                </span>
                <span class="lba-brand__name">{{ $brand?->name ?? $tenant->name }}</span>
            </a>

            <div class="hidden items-center gap-3 lg:flex">
                <a href="{{ url('/klinik/'.$tenant->slug.'/daftar') }}" class="lba-btn lba-btn--outline">{{ __('site.register_patient') }}</a>
                <a href="{{ url('/patient/login?tenant='.$tenant->slug) }}" class="lba-btn lba-btn--outline">{{ __('site.patient_login') }}</a>
                <a href="{{ url('/login?tenant='.$tenant->slug) }}" class="lba-btn lba-btn--outline">{{ __('site.staff_login') }}</a>
                <a href="{{ url('/klinik/'.$tenant->slug.'/booking') }}" class="lba-btn lba-btn--primary">{{ __('site.book') }}</a>
            </div>

            <details class="relative lg:hidden">
                <summary class="lba-btn lba-btn--outline list-none cursor-pointer [&::-webkit-details-marker]:hidden" aria-label="{{ __('site.open_menu') }}">
                    <span>{{ __('site.menu') }}</span>
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="M5 8l5 5 5-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </summary>
                <nav class="absolute right-0 top-[calc(100%+0.5rem)] w-64 rounded-xl border border-lba-line bg-white p-2 shadow-xl" aria-label="{{ __('site.mobile_nav') }}">
                    <a href="{{ url('/klinik/'.$tenant->slug.'/daftar') }}" class="lba-btn lba-btn--outline mb-2 w-full">{{ __('site.register_patient') }}</a>
                    <a href="{{ url('/patient/login?tenant='.$tenant->slug) }}" class="lba-btn lba-btn--outline mb-2 w-full">{{ __('site.patient_login') }}</a>
                    <a href="{{ url('/login?tenant='.$tenant->slug) }}" class="lba-btn lba-btn--outline mb-2 w-full">{{ __('site.staff_login') }}</a>
                    <a href="{{ url('/klinik/'.$tenant->slug.'/booking') }}" class="lba-btn lba-btn--primary w-full">{{ __('site.book') }}</a>
                </nav>
            </details>
        </div>
    </header>

    <main id="main">
        {{-- Hero --}}
        <section class="lba-container pt-12 sm:pt-16 lg:pt-20">
            <div class="lba-hero-panel px-6 py-14 sm:px-10 sm:py-16 lg:px-16 lg:py-24">
                <div class="relative z-10 max-w-3xl">
                    <p class="lba-eyebrow text-lba-mint">{{ $brand?->name ?? $tenant->name }}</p>
                    <h1 class="mt-4 text-4xl font-semibold leading-[1.08] tracking-tight text-white sm:text-5xl lg:text-6xl">
                        {{ $tenant->name }}
                    </h1>
                    <p class="mt-5 max-w-2xl text-base leading-relaxed text-white/75 sm:text-lg">
                        {{ __('site.default_hero_subtitle') }}
                    </p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <a href="{{ url('/klinik/'.$tenant->slug.'/booking') }}" class="lba-btn lba-btn--primary">{{ __('site.book') }}</a>
                        <a href="{{ url('/klinik/'.$tenant->slug.'/daftar') }}" class="lba-btn lba-btn--light">{{ __('site.register_patient') }}</a>
                    </div>
                </div>
            </div>
        </section>

        {{-- About --}}
        <section class="lba-section">
            <div class="lba-container">
                <div class="max-w-2xl">
                    <p class="lba-eyebrow">{{ __('site.about') }}</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-lba-navy sm:text-4xl">
                        {{ __('site.welcome_to', ['name' => $tenant->name]) }}
                    </h2>
                    <p class="mt-4 text-base leading-relaxed text-lba-ink">
                        {{ __('site.default_about_text') }}
                    </p>
                </div>
            </div>
        </section>

        {{-- Locations (active branches) --}}
        @if ($branches->count() > 0)
            <section class="lba-section border-t border-lba-line bg-white">
                <div class="lba-container">
                    <div class="max-w-2xl">
                        <p class="lba-eyebrow">{{ __('site.locations') }}</p>
                        <h2 class="mt-3 text-3xl font-semibold tracking-tight text-lba-navy sm:text-4xl">{{ __('site.our_locations') }}</h2>
                    </div>
                    <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($branches as $branch)
                            <article class="lba-card">
                                <h3 class="text-lg font-semibold text-lba-navy">{{ $branch->name }}</h3>
                                @if ($branch->address)
                                    <p class="mt-2 text-sm leading-relaxed text-lba-ink">{{ $branch->address }}</p>
                                @endif
                                @if ($branch->city || $branch->region)
                                    <p class="mt-1 text-sm text-lba-mist">
                                        {{ trim(($branch->city ?? '').', '.($branch->region ?? ''), ', ') }}
                                    </p>
                                @endif
                                @if ($branch->phone)
                                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $branch->phone) }}" class="mt-3 block text-sm text-lba-teal hover:underline">{{ $branch->phone }}</a>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        {{-- Default FAQ --}}
        <section class="lba-section">
            <div class="lba-container">
                <div class="max-w-2xl">
                    <p class="lba-eyebrow">{{ __('site.faq') }}</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-lba-navy sm:text-4xl">{{ __('site.faq_title') }}</h2>
                </div>
                <div class="mt-10 grid gap-4">
                    @foreach ([
                        ['q' => __('site.faq.q1.q'), 'a' => __('site.faq.q1.a')],
                        ['q' => __('site.faq.q2.q'), 'a' => __('site.faq.q2.a')],
                        ['q' => __('site.faq.q3.q'), 'a' => __('site.faq.q3.a')],
                    ] as $i => $item)
                        <details class="lba-card group" @if ($i === 0) open @endif>
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 [&::-webkit-details-marker]:hidden">
                                <span class="font-semibold text-lba-navy">{{ $item['q'] }}</span>
                                <svg class="h-5 w-5 shrink-0 text-lba-mist transition group-open:rotate-180" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M5 8l5 5 5-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </summary>
                            <p class="mt-3 text-sm leading-relaxed text-lba-ink">{{ $item['a'] }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- CTA --}}
        <section class="lba-section border-t border-lba-line bg-white">
            <div class="lba-container">
                <div class="lba-hero-panel px-6 py-12 text-center sm:px-10 sm:py-16">
                    <h2 class="text-3xl font-semibold tracking-tight text-white sm:text-4xl">{{ __('site.cta_title') }}</h2>
                    <p class="mx-auto mt-4 max-w-xl text-base leading-relaxed text-white/75">{{ __('site.cta_subtitle') }}</p>
                    <div class="mt-8 flex flex-wrap justify-center gap-3">
                        <a href="{{ url('/klinik/'.$tenant->slug.'/booking') }}" class="lba-btn lba-btn--primary">{{ __('site.book') }}</a>
                        <a href="{{ url('/klinik/'.$tenant->slug.'/daftar') }}" class="lba-btn lba-btn--light">{{ __('site.register_patient') }}</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-lba-line bg-white">
        <div class="lba-container flex flex-col gap-3 py-6 text-xs text-lba-mist sm:flex-row sm:items-center sm:justify-between">
            <p>{{ __('site.footer_tenant', ['name' => $tenant->name, 'year' => date('Y')]) }}</p>
        </div>
    </footer>
</body>
</html>
