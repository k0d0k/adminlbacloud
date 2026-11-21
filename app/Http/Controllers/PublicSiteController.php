<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Site\Models\SitePage as CentralSitePage;
use App\Platform\Site\Models\Tenant\SitePage as TenantSitePage;
use App\Platform\Site\SiteContentPublisher;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public website rendering for the platform landing page and tenant frontpages.
 *
 * The platform page (`/`) is read from the CENTRAL database so the anonymous
 * landing page never opens a tenant connection. Tenant frontpages
 * (`/klinik/{slug}` or a verified custom domain) initialise the tenant
 * database connection, read published content from that tenant, and purge the
 * connection in a `finally` block so it can never leak into a later request.
 */
final class PublicSiteController extends Controller
{
    public function __construct(
        private readonly SiteContentPublisher $publisher,
        private readonly ResolvesTenantDatabase $databases,
    ) {}

    /**
     * Render the platform (LBA Cloud) landing page from CMS, falling back to the
     * static `landing.blade.php` when no published version exists.
     */
    public function showPlatform(Request $request): View
    {
        $locale = $this->resolveLocale($request);

        try {
            $page = CentralSitePage::query()
                ->where('owner_type', CentralSitePage::OWNER_PLATFORM)
                ->whereNull('owner_id')
                ->where('slug', 'home')
                ->first();

            if ($page === null) {
                return view('landing');
            }

            $published = $this->publisher->getPublished($page, $locale);

            if ($published === null) {
                // Fall back to any published locale, then to the static page.
                foreach (['id', 'en', 'ms-MY'] as $fallback) {
                    if ($fallback !== $locale) {
                        $published = $this->publisher->getPublished($page, $fallback);
                        if ($published !== null) {
                            break;
                        }
                    }
                }
            }

            if ($published === null) {
                return view('landing');
            }

            return view('site.platform', [
                'content' => $published['content'] ?? [],
                'seo' => $published['seo'] ?? [],
                'title' => $published['title'] ?? config('app.name'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Platform CMS render failed, falling back to landing page', [
                'error' => $e->getMessage(),
            ]);

            return view('landing');
        }
    }

    /**
     * Render a tenant (clinic) frontpage. Resolves the active tenant by slug,
     * opens the tenant database connection, reads published CMS content, and
     * falls back to a default template or a service-unavailable page.
     */
    public function showTenant(Request $request, string $slug): View
    {
        $tenant = Tenant::query()
            ->where('slug', $slug)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->first();

        if ($tenant === null) {
            abort(404);
        }

        $locale = $this->resolveLocale($request);
        $tenantId = (string) $tenant->getKey();

        try {
            $this->databases->configure($tenantId);

            $page = TenantSitePage::query()
                ->where('owner_type', TenantSitePage::OWNER_TENANT)
                ->where('slug', 'home')
                ->first();

            if ($page === null) {
                return $this->defaultTenantTemplate($tenant);
            }

            $published = $this->publisher->getPublished($page, $locale);

            if ($published === null) {
                foreach (['id', 'en', 'ms-MY'] as $fallback) {
                    if ($fallback !== $locale) {
                        $published = $this->publisher->getPublished($page, $fallback);
                        if ($published !== null) {
                            break;
                        }
                    }
                }
            }

            if ($published === null) {
                return $this->defaultTenantTemplate($tenant);
            }

            return view('site.tenant', [
                'tenant' => $tenant,
                'content' => $published['content'] ?? [],
                'seo' => $published['seo'] ?? [],
                'title' => $published['title'] ?? $tenant->name,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Tenant CMS render failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->view('site.service-unavailable', [
                'tenant' => $tenant,
            ], 503);
        } finally {
            $this->databases->forget();
        }
    }

    /**
     * Default clinic template shown when a tenant has no published CMS content.
     * Uses only public tenant data (name, brand, active branches) — never
     * clinical data. Branches are read from the tenant DB connection already
     * configured by the caller.
     */
    private function defaultTenantTemplate(Tenant $tenant): View
    {
        $brands = $tenant->brands;
        $brand = $brands->firstWhere(fn ($b) => (bool) ($b->pivot->is_primary ?? false))
            ?? $brands->first();

        return view('site.tenant-default', [
            'tenant' => $tenant,
            'brand' => $brand,
            'branches' => TenantBranch::query()->active()->get(),
        ]);
    }

    private function resolveLocale(Request $request): string
    {
        $locale = $request->getPreferredLanguage(['id', 'en', 'ms-MY']);

        return in_array($locale, ['id', 'en', 'ms-MY'], true) ? $locale : 'id';
    }
}
