<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('lba.subscription.inactive_title') }} · {{ $systemName }}</title>
    {{--
        Deliberately dependency-free: this notice must render even when the
        tenant database or the compiled theme is unavailable, so it ships its
        own minimal styles instead of loading the panel assets.
    --}}
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 1.5rem;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
        }
        .card {
            width: min(34rem, 100%);
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .08);
        }
        .badge {
            display: inline-block;
            padding: .25rem .6rem;
            border-radius: 999px;
            background: #fef3c7;
            color: #92400e;
            font-size: .75rem;
            font-weight: 600;
        }
        h1 { margin: 1rem 0 .5rem; font-size: 1.35rem; color: #0f172a; }
        p { margin: .5rem 0; line-height: 1.6; }
        .muted { color: #64748b; font-size: .9rem; }
        .tenant { font-weight: 600; color: #0f172a; }
        .actions { margin-top: 1.5rem; display: flex; flex-wrap: wrap; gap: .75rem; }
        button {
            cursor: pointer;
            border: 0;
            border-radius: .6rem;
            padding: .6rem 1.1rem;
            font-size: .9rem;
            font-weight: 600;
            background: #087f80;
            color: #fff;
        }
        button.secondary { background: transparent; color: #334155; border: 1px solid #cbd5e1; }
    </style>
</head>
<body>
    <main class="card">
        <span class="badge">{{ __('lba.subscription.inactive_badge') }}</span>
        <h1>{{ __('lba.subscription.inactive_title') }}</h1>

        @if ($tenantName)
            <p>{{ __('lba.subscription.inactive_tenant', ['tenant' => $tenantName]) }}</p>
        @endif

        <p>{{ __('lba.subscription.inactive_body') }}</p>
        <p class="muted">{{ __('lba.subscription.inactive_help') }}</p>

        <div class="actions">
            <form method="post" action="{{ route('filament.clinic.auth.logout') }}">
                @csrf
                <button type="submit">{{ __('principal.shell.logout') }}</button>
            </form>
        </div>
    </main>
</body>
</html>
