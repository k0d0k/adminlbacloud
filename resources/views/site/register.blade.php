<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ __('site.registration.title') }} — {{ $tenant->name }}</title>
    <meta name="description" content="{{ __('site.registration.subtitle', ['clinic' => $tenant->name]) }}">
    <meta name="theme-color" content="#14263D">
    <meta name="robots" content="noindex, follow">
    <link rel="canonical" href="{{ route('tenant.register', $tenant->slug) }}">

    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}">

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
                <span class="lba-brand__name hidden sm:inline">{{ $tenant->name }}</span>
            </a>

            <a href="{{ route('tenant.frontpage', $tenant->slug) }}" class="lba-btn lba-btn--outline">{{ __('site.registration.back') }}</a>
        </div>
    </header>

    <main id="main" class="lba-section">
        <div class="lba-container max-w-2xl">
            <p class="lba-eyebrow">{{ __('site.register_patient') }}</p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-lba-navy sm:text-4xl">
                {{ __('site.registration.title') }}
            </h1>
            <p class="mt-3 text-base leading-relaxed text-lba-ink">
                {{ __('site.registration.subtitle', ['clinic' => $tenant->name]) }}
            </p>

            @if ($status)
                <div class="mt-6 rounded-xl border border-lba-line bg-white p-4 text-sm text-lba-ink" role="status">
                    {{ $status }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">
                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('tenant.register.store', $tenant->slug) }}" class="lba-card mt-8 grid gap-4">
                @csrf

                <label class="grid gap-1 text-sm font-medium text-lba-navy">
                    {{ __('site.registration.name') }}
                    <input type="text" name="name" value="{{ old('name') }}" required autocomplete="name"
                        class="rounded-lg border border-lba-line px-3 py-2 text-base font-normal text-lba-ink" />
                </label>

                <label class="grid gap-1 text-sm font-medium text-lba-navy">
                    {{ __('site.registration.email') }}
                    <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"
                        class="rounded-lg border border-lba-line px-3 py-2 text-base font-normal text-lba-ink" />
                </label>

                <label class="grid gap-1 text-sm font-medium text-lba-navy">
                    {{ __('site.registration.phone') }} <span class="font-normal text-lba-mist">({{ __('site.registration.optional') }})</span>
                    <input type="tel" name="phone" value="{{ old('phone') }}" autocomplete="tel"
                        class="rounded-lg border border-lba-line px-3 py-2 text-base font-normal text-lba-ink" />
                </label>

                <label class="grid gap-1 text-sm font-medium text-lba-navy">
                    {{ __('site.registration.password') }}
                    <input type="password" name="password" required autocomplete="new-password"
                        class="rounded-lg border border-lba-line px-3 py-2 text-base font-normal text-lba-ink" />
                </label>

                <label class="grid gap-1 text-sm font-medium text-lba-navy">
                    {{ __('site.registration.password_confirmation') }}
                    <input type="password" name="password_confirmation" required autocomplete="new-password"
                        class="rounded-lg border border-lba-line px-3 py-2 text-base font-normal text-lba-ink" />
                </label>

                <label class="flex items-start gap-2 text-sm text-lba-ink">
                    <input type="checkbox" name="privacy" value="1" required class="mt-1" />
                    <span>{{ __('site.registration.privacy') }}</span>
                </label>

                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <button type="submit" class="lba-btn lba-btn--primary">{{ __('site.registration.submit') }}</button>
                    <a href="{{ url('/patient/login?tenant='.$tenant->slug) }}" class="lba-btn lba-btn--outline">{{ __('site.patient_login') }}</a>
                </div>

                <p class="text-xs leading-relaxed text-lba-mist">{{ __('site.registration.verify_hint') }}</p>
            </form>

            @if ($branches->count() > 0)
                <section class="mt-10">
                    <p class="lba-eyebrow">{{ __('site.locations') }}</p>
                    <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                        @foreach ($branches as $branch)
                            <li class="lba-card text-sm">
                                <span class="font-semibold text-lba-navy">{{ $branch->name }}</span>
                                @if ($branch->city || $branch->region)
                                    <span class="block text-lba-mist">{{ trim(($branch->city ?? '').', '.($branch->region ?? ''), ', ') }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </main>

    <footer class="border-t border-lba-line bg-white">
        <div class="lba-container py-6 text-xs text-lba-mist">
            <p>{{ __('site.footer_tenant', ['name' => $tenant->name, 'year' => date('Y')]) }}</p>
        </div>
    </footer>
</body>
</html>
