<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PermissionKey;
use App\Platform\Site\Models\Tenant\SiteMedia as TenantSiteMedia;
use App\Platform\Site\Models\Tenant\SitePage as TenantSitePage;
use App\Platform\Site\RichTextSanitizer;
use App\Platform\Site\SiteContentPublisher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Clinic (tenant) website CMS editor (TailAdmin).
 *
 * Only a Tenant Owner or Clinic Admin holding `tenant.site.manage` for the
 * CURRENT tenant may edit that clinic's public frontpage. Content is stored in
 * the tenant's OWN isolated database — never copied to the central database.
 *
 * The route group already ensures a verified clinic tenant context
 * ({@see EnsureClinicTenantContext}); this controller re-checks the permission
 * so a staff member without site management rights is denied even if they reach
 * the URL directly.
 */
final class SiteController extends Controller
{
    private const LOCALES = ['id', 'en', 'ms-MY'];

    public function __construct(
        private readonly ChecksPermissions $permissions,
        private readonly ManagesTenantContext $tenants,
    ) {}

    public function edit(Request $request, SiteContentPublisher $publisher): View
    {
        $this->authorizeSite($request);

        $page = $publisher->getOrCreatePage(TenantSitePage::OWNER_TENANT);
        $locale = $request->string('locale', 'id')->toString();
        $locale = in_array($locale, self::LOCALES, true) ? $locale : 'id';

        $user = $this->actor($request);
        $tenantId = $this->tenants->currentTenantId($user);
        $tenant = $tenantId !== null ? Tenant::query()->find($tenantId) : null;

        return view('clinic.site.edit', [
            'page' => $page,
            'locale' => $locale,
            'locales' => self::LOCALES,
            'draft' => $publisher->getDraft($page, $locale),
            'published' => $publisher->getPublished($page, $locale),
            'revisions' => $publisher->listRevisions($page),
            'links' => WorkspaceController::navigation(),
            'tenantSlug' => $tenant?->slug,
            'media' => TenantSiteMedia::query()->latest()->get(),
        ]);
    }

    public function updateDraft(Request $request, SiteContentPublisher $publisher, RichTextSanitizer $sanitizer): RedirectResponse
    {
        $this->authorizeSite($request);

        $data = $request->validate([
            'locale' => ['required', 'string', 'in:id,en,ms-MY'],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'array'],
            'seo' => ['nullable', 'array'],
            'seo.title' => ['nullable', 'string', 'max:255'],
            'seo.description' => ['nullable', 'string', 'max:500'],
            'seo.og_image' => ['nullable', 'string', 'max:1000'],
        ]);

        $page = $publisher->getOrCreatePage(TenantSitePage::OWNER_TENANT);

        $publisher->saveDraft(
            page: $page,
            locale: $data['locale'],
            content: $sanitizer->sanitizeSections($data['content']),
            title: $data['title'] ?? null,
            seo: $data['seo'] ?? null,
            actorId: $this->actor($request)?->getKey(),
        );

        return back()->with('success', __('clinic.site.draft_saved'));
    }

    public function publish(Request $request, SiteContentPublisher $publisher): RedirectResponse
    {
        $this->authorizeSite($request);

        $data = $request->validate([
            'locale' => ['required', 'string', 'in:id,en,ms-MY'],
        ]);

        $page = $publisher->getOrCreatePage(TenantSitePage::OWNER_TENANT);
        $revision = $publisher->publish($page, $data['locale'], $this->actor($request)?->getKey());

        if ($revision === null) {
            return back()->with('error', __('clinic.site.no_draft'));
        }

        return back()->with('success', __('clinic.site.published'));
    }

    public function restore(Request $request, SiteContentPublisher $publisher): RedirectResponse
    {
        $this->authorizeSite($request);

        $data = $request->validate([
            'revision_id' => ['required', 'string'],
        ]);

        $page = $publisher->getOrCreatePage(TenantSitePage::OWNER_TENANT);
        $restored = $publisher->restoreRevision($page, $data['revision_id'], $this->actor($request)?->getKey());

        if ($restored === null) {
            return back()->with('error', __('clinic.site.revision_not_found'));
        }

        return back()->with('success', __('clinic.site.restored'));
    }

    private function authorizeSite(Request $request): void
    {
        $user = $this->actor($request);
        abort_unless($user !== null, 403);

        $tenantId = $this->tenants->currentTenantId($user);
        abort_unless($tenantId !== null, 403);

        abort_unless(
            $this->permissions->allows($user, PermissionKey::TENANT_SITE_MANAGE, AccessContext::forTenant($tenantId)),
            403,
        );
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
