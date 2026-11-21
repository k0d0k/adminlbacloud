<?php

namespace App\Platform\Tenancy\Http\Middleware;

use App\Http\Controllers\PublicSiteController;
use App\Models\Tenant;
use App\Platform\Tenancy\Domain\Contracts\ResolvesTenantByDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves a verified custom domain to its tenant and serves the tenant
 * frontpage at the root path.
 *
 * Security contract:
 *  - only a VERIFIED tenant domain (with non-null verified_at) resolves;
 *  - the bound tenant must still exist and be active;
 *  - reserved platform paths (/principal, /clinic, /patient, /login, /api,
 *    /klinik, /staff, /account, /.well-known) always pass through to normal
 *    routing, so a tenant domain can never hijack platform entry points;
 *  - login from a custom domain is forwarded to the trusted APP_URL host with
 *    the clinic context as a validated query parameter;
 *  - any other path on a tenant domain returns 404 (fail closed).
 *
 * This middleware does NOT open a tenant database connection — that is the
 * responsibility of {@see PublicSiteController} which purges it in a finally
 * block. The resolver only returns identity metadata from the central DB.
 */
final class ResolveTenantDomain
{
    /** @var list<string> Path prefixes that must always reach platform routing. */
    private const RESERVED_PREFIXES = [
        'principal',
        'clinic',
        'patient',
        'login',
        'logout',
        'api',
        'klinik',
        'registrasi',
        'staff',
        'account',
        'livewire',
        '.well-known',
        'up',
    ];

    public function __construct(
        private readonly ResolvesTenantByDomain $domains,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        // The platform's own host serves platform routes verbatim.
        if ($this->isPlatformHost($host)) {
            return $next($request);
        }

        $tenant = $this->domains->resolveHost($host);

        // Unknown / unverified host on a non-platform domain: 404 rather than
        // leaking the platform landing page.
        if (! $tenant instanceof Tenant) {
            abort(404);
        }

        $path = trim($request->path(), '/');

        // Reserved platform paths pass through to normal routing.
        if ($this->isReservedPath($path)) {
            // Login flows are forwarded to the trusted APP_URL host with the
            // clinic context preserved as a validated tenant slug.
            if ($path === 'login' || $path === 'patient/login') {
                return redirect()->away($this->platformUrl($path, $tenant));
            }

            return $next($request);
        }

        // Root path serves the tenant frontpage.
        if ($path === '') {
            return app(PublicSiteController::class)->showTenant($request, $tenant->slug);
        }

        // Tenant public actions map to the canonical /klinik/{slug} routes.
        return match ($path) {
            'daftar' => redirect()->to('/klinik/'.$tenant->slug.'/daftar'),
            'booking' => redirect()->to('/klinik/'.$tenant->slug.'/booking'),
            default => abort(404),
        };
    }

    private function isPlatformHost(string $host): bool
    {
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);

        if ($appHost === false || $appHost === null) {
            return false;
        }

        // Strip an optional "www." prefix from both sides for the comparison.
        $normalize = static fn (string $h): string => preg_replace('/^www\./i', '', strtolower($h));

        return $normalize($host) === $normalize($appHost);
    }

    private function isReservedPath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        foreach (self::RESERVED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function platformUrl(string $path, Tenant $tenant): string
    {
        $base = rtrim(config('app.url'), '/');

        return $base.'/'.$path.'?tenant='.urlencode($tenant->slug);
    }
}
