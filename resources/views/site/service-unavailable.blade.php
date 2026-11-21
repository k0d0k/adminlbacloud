<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $tenant->name }} — {{ __('site.service_unavailable') }}</title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#14263D">

    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}">

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="lba-landing">
    <header class="border-b border-lba-line bg-white">
        <div class="lba-container flex h-16 items-center justify-between gap-4">
            <a href="{{ route('tenant.frontpage', $tenant->slug) }}" class="lba-brand" aria-label="{{ $tenant->name }}">
                <span class="lba-brand__mark" aria-hidden="true">
                    <svg viewBox="0 0 32 32" fill="none">
                        <path d="M16 3 27 9.5v13L16 29 5 22.5v-13L16 3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                        <circle cx="16" cy="16" r="4.5" fill="currentColor" />
                    </svg>
                </span>
                <span class="lba-brand__name">{{ $tenant->name }}</span>
            </a>
        </div>
    </header>

    <main class="lba-container py-16 sm:py-24">
        <div class="mx-auto max-w-xl text-center">
            <p class="lba-eyebrow">{{ __('site.503') }}</p>
            <h1 class="mt-4 text-3xl font-semibold tracking-tight text-lba-navy sm:text-4xl">
                {{ __('site.service_temporarily_unavailable') }}
            </h1>
            <p class="mt-4 text-base leading-relaxed text-lba-ink">
                {{ __('site.service_unavailable_message') }}
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ route('tenant.frontpage', $tenant->slug) }}" class="lba-btn lba-btn--primary">{{ __('site.try_again') }}</a>
                <a href="{{ url('/') }}" class="lba-btn lba-btn--outline">{{ __('site.back_to_platform') }}</a>
            </div>
        </div>
    </main>

    <footer class="border-t border-lba-line bg-white">
        <div class="lba-container py-6 text-xs text-lba-mist">
            <p>{{ __('site.footer_tenant', ['name' => $tenant->name, 'year' => date('Y')]) }}</p>
        </div>
    </footer>
</body>
</html>
