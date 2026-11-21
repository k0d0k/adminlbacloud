<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 — Akses Ditolak</title>
    @php
        use App\Platform\Access\PanelAccessPolicy;

        $user = auth()->user();
        $isGuest = $user === null;

        // Determine the panel the user is allowed to access by their role.
        $allowedPanel = null;
        if (! $isGuest) {
            $role = method_exists($user, 'getAttribute') ? $user->role : null;
            $map = PanelAccessPolicy::roleMap();
            $allowedPanel = $map[$role] ?? null;
        }

        // Determine which panel the user is currently trying to reach from the
        // request path. This lets us distinguish "wrong panel" from "right panel but no
        // active membership".
        $currentPath = request()->path();
        $currentPanel = match (true) {
            str_starts_with($currentPath, 'principal') => 'principal',
            str_starts_with($currentPath, 'clinic') => 'clinic',
            str_starts_with($currentPath, 'patient') => 'patient',
            default => null,
        };

        $samePanel = $allowedPanel !== null && $allowedPanel === $currentPanel;

        $panelLabels = [
            'principal' => 'Dashboard Principal',
            'clinic' => 'Dashboard Klinik',
            'patient' => 'Dashboard Pasien',
        ];

        // Only auto-redirect only when the user is on the WRONG panel. When they
        // are on the correct panel, the 403 is a membership/context problem
        // (e.g. no active clinic membership) — redirecting to the same URL would
        // loop, so we show a clear message + logout instead.
        $targetUrl = null;
        if ($isGuest) {
            $targetUrl = route('login');
        } elseif ($allowedPanel !== null && ! $samePanel) {
            $targetUrl = url('/'.$allowedPanel);
        }

        // Logout URL: each Filament panel registers its own logout route. Use the
        // panel the user actually belongs to; fall back to principal for users
        // with no mapped role.
        $logoutPanel = $allowedPanel ?? 'principal';
        $logoutRoute = 'filament.'.$logoutPanel.'.auth.logout';
        $logoutUrl = route($logoutRoute);

        // Distinguish the two distinct 403 causes on the correct panel:
        //  - the Clinic tenant guard aborts with lba.tenant_guard.no_active_partner
        //    when the identity has no active membership;
        //  - every other 403 (Filament canViewAny/canCreate, FeatureGate, branch
        //    scope) is a permission denial, NOT a missing membership.
        // Showing "no active partner" for a permission denial misleads the user
        // into thinking their membership is broken when it really is fine.
        $exceptionMessage = $exception instanceof Throwable ? $exception->getMessage() : '';
        $tenantGuardMessage = __('lba.tenant_guard.no_active_partner');
        $isTenantGuard = $exceptionMessage !== '' && $exceptionMessage === $tenantGuardMessage;
    @endphp
    @if ($targetUrl)
        <meta http-equiv="refresh" content="3;url={{ $targetUrl }}">
    @endif
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1.5rem;
        }
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 2rem;
            max-width: 32rem;
            width: 100%;
            text-align: center;
            box-shadow: 0 1px 2px rgba(15,23,42,.04);
        }
        .code {
            font-size: 3rem;
            font-weight: 800;
            color: #087F80;
            margin: 0;
            line-height: 1;
        }
        h1 {
            font-size: 1.25rem;
            margin: .5rem 0 .25rem;
        }
        p { color: #475569; margin: .35rem 0; line-height: 1.5; }
        .hint { font-size: .875rem; color: #64748b; }
        .actions { margin-top: 1.25rem; display: flex; flex-wrap: wrap; gap: .5rem; justify-content: center; }
        .btn {
            display: inline-block;
            background: #087F80;
            color: #fff;
            text-decoration: none;
            padding: .625rem 1.25rem;
            border-radius: .5rem;
            font-weight: 600;
            font-size: .9rem;
            border: none;
            cursor: pointer;
        }
        .btn:hover { background: #066a6b; }
        .btn.secondary {
            background: transparent;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        .btn.secondary:hover { background: #f1f5f9; }
        .btn.danger {
            background: #dc2626;
        }
        .btn.danger:hover { background: #b91c1c; }
        form.logout { display: inline; }
    </style>
</head>
<body>
    <div class="card">
        <p class="code">403</p>

        @if ($isGuest)
            <h1>Sesi Anda telah berakhir</h1>
            <p>Silakan masuk kembali untuk melanjutkan.</p>
            <p class="hint">Anda akan diarahkan ke halaman login dalam 3 detik…</p>
            <div class="actions">
                <a class="btn" href="{{ route('login') }}">Masuk Sekarang</a>
            </div>
        @elseif ($allowedPanel === null)
            <h1>Akses Ditolak</h1>
            <p>
                Akun <strong>{{ e($user->email) }}</strong> tidak memiliki akses ke
                panel mana pun. Hubungi administrator platform.
            </p>
            <div class="actions">
                <a class="btn" href="{{ route('login') }}">Keluar &amp; Masuk Lagi</a>
            </div>
        @elseif ($samePanel && $isTenantGuard)
            <h1>{{ __('lba.tenant_guard.no_active_partner') }}</h1>
            <p>
                Akun <strong>{{ e($user->email) }}</strong> memiliki peran
                <strong>{{ e($user->role) }}</strong>, namun belum memiliki
                keanggotaan aktif ke mitra mana pun.
            </p>
            <p class="hint">
                Hubungi administrator mitra untuk mengaktifkan keanggotaan Anda, atau
                keluar lalu masuk dengan akun yang sesuai.
            </p>
            <div class="actions">
                <form method="post" action="{{ $logoutUrl }}" class="logout">
                    @csrf
                    <button type="submit" class="btn danger">Keluar</button>
                </form>
                <a class="btn secondary" href="{{ url('/') }}">Kembali ke Beranda</a>
            </div>
        @elseif ($samePanel)
            <h1>Anda tidak memiliki izin untuk aksi ini</h1>
            <p>
                Akun <strong>{{ e($user->email) }}</strong> dengan peran
                <strong>{{ e($user->role) }}</strong> tidak memiliki izin untuk
                mengakses halaman atau melakukan aksi ini.
            </p>
            <p class="hint">
                Hubungi administrator mitra untuk menyesuaikan peran dan izin Anda,
                atau kembali ke halaman sebelumnya.
            </p>
            <div class="actions">
                <a class="btn" href="{{ url()->previous() }}">Kembali</a>
                <form method="post" action="{{ $logoutUrl }}" class="logout">
                    @csrf
                    <button type="submit" class="btn secondary">Keluar</button>
                </form>
            </div>
        @else
            <h1>Halaman ini tidak untuk peran Anda</h1>
            <p>
                Akun <strong>{{ e($user->email) }}</strong> memiliki peran
                <strong>{{ e($user->role) }}</strong>, yang hanya dapat mengakses
                <strong>{{ $panelLabels[$allowedPanel] ?? $allowedPanel }}</strong>.
            </p>
            <p class="hint">Anda akan diarahkan ke panel yang benar dalam 3 detik…</p>
            <div class="actions">
                <a class="btn" href="{{ url('/'.$allowedPanel) }}">Buka {{ $panelLabels[$allowedPanel] ?? $allowedPanel }}</a>
                <form method="post" action="{{ $logoutUrl }}" class="logout">
                    @csrf
                    <button type="submit" class="btn secondary">Ganti Akun</button>
                </form>
            </div>
        @endif
    </div>
</body>
</html>
