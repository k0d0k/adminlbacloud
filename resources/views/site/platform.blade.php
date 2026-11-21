<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title ?? config('app.name') }}</title>
    <meta name="description" content="{{ $seo['description'] ?? config('app.name') }}">
    <meta name="theme-color" content="#14263D">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ url('/') }}">

    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ $seo['title'] ?? $title ?? config('app.name') }}">
    <meta property="og:description" content="{{ $seo['description'] ?? config('app.name') }}">
    <meta property="og:url" content="{{ url('/') }}">
    @if (!empty($seo['og_image']))<meta property="og:image" content="{{ $seo['og_image'] }}">@endif
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seo['title'] ?? $title ?? config('app.name') }}">
    <meta name="twitter:description" content="{{ $seo['description'] ?? config('app.name') }}">
    @if (!empty($seo['og_image']))<meta name="twitter:image" content="{{ $seo['og_image'] }}">@endif

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
            <a href="{{ url('/') }}" class="lba-brand" aria-label="{{ config('app.name') }} — {{ __('site.home') }}">
                <span class="lba-brand__mark" aria-hidden="true">
                    <svg viewBox="0 0 32 32" fill="none">
                        <path d="M16 3 27 9.5v13L16 29 5 22.5v-13L16 3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                        <circle cx="16" cy="16" r="4.5" fill="currentColor" />
                    </svg>
                </span>
                <span class="lba-brand__name">{{ config('app.name') }}</span>
            </a>

            <div class="hidden items-center gap-3 lg:flex">
                <a href="{{ route('login') }}" class="lba-btn lba-btn--outline">{{ __('site.staff_login') }}</a>
                <a href="{{ url('/patient/login') }}" class="lba-btn lba-btn--primary">{{ __('site.patient_portal') }}</a>
            </div>

            <details class="relative lg:hidden">
                <summary class="lba-btn lba-btn--outline list-none cursor-pointer [&::-webkit-details-marker]:hidden" aria-label="{{ __('site.open_menu') }}">
                    <span>{{ __('site.menu') }}</span>
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="M5 8l5 5 5-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </summary>
                <nav class="absolute right-0 top-[calc(100%+0.5rem)] w-64 rounded-xl border border-lba-line bg-white p-2 shadow-xl" aria-label="{{ __('site.mobile_nav') }}">
                    <a href="{{ route('login') }}" class="lba-btn lba-btn--outline mb-2 w-full">{{ __('site.staff_login') }}</a>
                    <a href="{{ url('/patient/login') }}" class="lba-btn lba-btn--primary w-full">{{ __('site.patient_portal') }}</a>
                </nav>
            </details>
        </div>
    </header>

    <main id="main">
        @include('site._sections', ['content' => $content ?? []])
    </main>

    <footer class="border-t border-lba-line bg-white">
        <div class="lba-container grid gap-10 py-14 sm:grid-cols-2 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <a href="{{ url('/') }}" class="lba-brand" aria-label="{{ config('app.name') }} — {{ __('site.home') }}">
                    <span class="lba-brand__mark" aria-hidden="true">
                        <svg viewBox="0 0 32 32" fill="none">
                            <path d="M16 3 27 9.5v13L16 29 5 22.5v-13L16 3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                            <circle cx="16" cy="16" r="4.5" fill="currentColor" />
                        </svg>
                    </span>
                    <span class="lba-brand__name">{{ config('app.name') }}</span>
                </a>
                <p class="mt-4 max-w-sm text-sm leading-relaxed text-lba-ink">
                    {{ $seo['description'] ?? config('app.name') }}
                </p>
            </div>

            <nav aria-label="{{ __('site.access_nav') }}">
                <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-lba-mist">{{ __('site.access') }}</h2>
                <ul class="mt-4 space-y-2.5 text-sm">
                    <li><a href="{{ route('login') }}" class="text-lba-ink transition hover:text-lba-teal">{{ __('site.staff_login') }}</a></li>
                    <li><a href="{{ url('/patient/login') }}" class="text-lba-ink transition hover:text-lba-teal">{{ __('site.patient_portal') }}</a></li>
                </ul>
            </nav>
        </div>

        <div class="border-t border-lba-line">
            <div class="lba-container flex flex-col gap-3 py-6 text-xs text-lba-mist sm:flex-row sm:items-center sm:justify-between">
                <p>{{ __('lba.auth.legal', ['year' => date('Y')]) }}</p>
            </div>
        </div>
    </footer>
</body>
</html>
