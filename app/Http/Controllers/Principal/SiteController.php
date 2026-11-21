<?php

namespace App\Http\Controllers\Principal;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\Site\Models\SiteMedia;
use App\Platform\Site\Models\SitePage;
use App\Platform\Site\RichTextSanitizer;
use App\Platform\Site\SiteContentPublisher;
use App\Platform\Site\SiteMediaStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Principal website CMS editor (TailAdmin).
 *
 * Only an active Principal holding `platform.site.manage` may edit the LBA
 * Cloud public frontpage. Content is stored in the CENTRAL database so the
 * anonymous landing page never opens a tenant connection.
 */
final class SiteController extends Controller
{
    use AuthorizesPrincipalResources;

    private const LOCALES = ['id', 'en', 'ms-MY'];

    public function edit(SiteContentPublisher $publisher): View
    {
        $this->authorizeSite();

        $page = $publisher->getOrCreatePage(SitePage::OWNER_PLATFORM);
        $locale = request()->string('locale', 'id')->toString();
        $locale = in_array($locale, self::LOCALES, true) ? $locale : 'id';

        $draft = $publisher->getDraft($page, $locale);
        $published = $publisher->getPublished($page, $locale);

        return view('principal.site.edit', [
            'page' => $page,
            'locale' => $locale,
            'locales' => self::LOCALES,
            'draft' => $draft,
            'published' => $published,
            'revisions' => $publisher->listRevisions($page),
            'media' => SiteMedia::query()
                ->where('owner_type', SiteMediaStore::OWNER_PLATFORM)
                ->latest()
                ->get(),
        ]);
    }

    public function updateDraft(Request $request, SiteContentPublisher $publisher, RichTextSanitizer $sanitizer): RedirectResponse
    {
        $this->authorizeSite();

        $data = $request->validate([
            'locale' => ['required', 'string', 'in:id,en,ms-MY'],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'array'],
            'seo' => ['nullable', 'array'],
            'seo.title' => ['nullable', 'string', 'max:255'],
            'seo.description' => ['nullable', 'string', 'max:500'],
            'seo.og_image' => ['nullable', 'string', 'max:1000'],
        ]);

        $page = $publisher->getOrCreatePage(SitePage::OWNER_PLATFORM);

        $publisher->saveDraft(
            page: $page,
            locale: $data['locale'],
            content: $sanitizer->sanitizeSections($data['content']),
            title: $data['title'] ?? null,
            seo: $data['seo'] ?? null,
            actorId: $this->actor()?->getKey(),
        );

        return back()->with('success', __('principal.site.draft_saved'));
    }

    public function publish(Request $request, SiteContentPublisher $publisher): RedirectResponse
    {
        $this->authorizeSite();

        $data = $request->validate([
            'locale' => ['required', 'string', 'in:id,en,ms-MY'],
        ]);

        $page = $publisher->getOrCreatePage(SitePage::OWNER_PLATFORM);
        $revision = $publisher->publish($page, $data['locale'], $this->actor()?->getKey());

        if ($revision === null) {
            return back()->with('error', __('principal.site.no_draft_to_publish'));
        }

        return back()->with('success', __('principal.site.published'));
    }

    public function restore(Request $request, SiteContentPublisher $publisher): RedirectResponse
    {
        $this->authorizeSite();

        $data = $request->validate([
            'revision_id' => ['required', 'string'],
        ]);

        $page = $publisher->getOrCreatePage(SitePage::OWNER_PLATFORM);
        $restored = $publisher->restoreRevision($page, $data['revision_id'], $this->actor()?->getKey());

        if ($restored === null) {
            return back()->with('error', __('principal.site.revision_not_found'));
        }

        return back()->with('success', __('principal.site.revision_restored'));
    }

    private function authorizeSite(): void
    {
        abort_unless(self::principalCan(PermissionKey::PLATFORM_SITE_MANAGE), 403);
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
