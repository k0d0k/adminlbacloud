<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\AuthorizesPrincipal;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\PermissionKey;
use App\Platform\Site\Models\SiteMedia as CentralSiteMedia;
use App\Platform\Site\Models\SitePage as CentralSitePage;
use App\Platform\Site\Models\Tenant\SiteMedia as TenantSiteMedia;
use App\Platform\Site\Models\Tenant\SitePage as TenantSitePage;
use App\Platform\Site\SiteMediaStore;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public, published-gated streaming of website CMS media.
 *
 * A media object is served ONLY while its owning page is published; media that
 * exists solely on a DRAFT page is refused to the public (403) and can be
 * previewed by an authorised editor only. The controller recovers the object
 * from the CMS disk by its stored path and REFUSES anything whose disk/prefix is
 * not the CMS media disk, so the endpoint can never be turned into a read of a
 * private clinical file (AC-10, TR-12.6).
 */
final class SiteMediaPublicController extends Controller
{
    public function __construct(
        private readonly ResolvesTenantDatabase $databases,
        private readonly SiteMediaStore $store,
    ) {}

    public function showPlatform(Request $request, string $media): StreamedResponse
    {
        $asset = CentralSiteMedia::query()
            ->where('owner_type', SiteMediaStore::OWNER_PLATFORM)
            ->whereKey($media)
            ->first();

        abort_if($asset === null, 404);
        abort_unless($this->store->isServable($asset->disk, $asset->path), 404);

        $page = CentralSitePage::query()
            ->where('owner_type', CentralSitePage::OWNER_PLATFORM)
            ->whereNull('owner_id')
            ->where('slug', 'home')
            ->first();

        abort_unless(($page?->isPublished() ?? false) || $this->principalCanPreview(), 403);

        return $this->stream($asset->disk, $asset->path, $asset->mime_type);
    }

    public function showTenant(Request $request, string $slug, string $media): StreamedResponse
    {
        $tenant = Tenant::query()
            ->where('slug', $slug)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->first();

        abort_if($tenant === null, 404);

        $tenantId = (string) $tenant->getKey();

        try {
            $this->databases->configure($tenantId);

            $asset = TenantSiteMedia::query()->whereKey($media)->first();

            abort_if($asset === null, 404);
            abort_unless($this->store->isServable($asset->disk, $asset->path), 404);

            $page = TenantSitePage::query()
                ->where('owner_type', TenantSitePage::OWNER_TENANT)
                ->where('slug', 'home')
                ->first();

            abort_unless(($page?->isPublished() ?? false) || $this->tenantCanPreview($request, $tenantId), 403);

            return $this->stream($asset->disk, $asset->path, $asset->mime_type);
        } finally {
            $this->databases->forget();
        }
    }

    private function stream(string $disk, string $path, ?string $mime): StreamedResponse
    {
        $response = Storage::disk($disk)->response($path);

        $response->headers->set('Content-Type', $mime ?: 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'public, max-age=3600');

        // An SVG can carry script; serve it under a locked-down policy.
        if ($mime === 'image/svg+xml') {
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            );
        }

        return $response;
    }

    private function principalCanPreview(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->is_active) {
            return false;
        }

        return app(AuthorizesPrincipal::class)->can($user, PermissionKey::PLATFORM_SITE_MANAGE);
    }

    private function tenantCanPreview(Request $request, string $tenantId): bool
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        return app(ChecksPermissions::class)->allows(
            $user,
            PermissionKey::TENANT_SITE_MANAGE,
            AccessContext::forTenant($tenantId),
        );
    }
}
