<?php

namespace App\Platform\Site;

use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\Site\Models\SitePage as CentralSitePage;
use App\Platform\Site\Models\SitePageRevision as CentralSitePageRevision;
use App\Platform\Site\Models\Tenant\SitePage as TenantSitePage;
use App\Platform\Site\Models\Tenant\SitePageRevision as TenantSitePageRevision;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Draft/publish/restore workflow for website CMS content.
 *
 * Works with BOTH platform (central) and tenant (clinic) site pages — the
 * caller passes a SitePage model from the correct connection and this service
 * operates on its revisions. Every publish/restore is audit-logged in the
 * central ModuleAuditLog (governance metadata, never clinical data).
 *
 * Content is a JSON array of sections: [{type, data, visible, order}, ...].
 * Each locale (id/en/ms-MY) has its own revision chain.
 */
final class SiteContentPublisher
{
    public const MODULE_KEY = 'platform.site';

    public const ACTION_DRAFTED = 'drafted';

    public const ACTION_PUBLISHED = 'published';

    public const ACTION_RESTORED = 'restored';

    /**
     * Get or create the site page for an owner.
     *
     * @param  'platform'|'tenant'  $ownerType
     */
    public function getOrCreatePage(string $ownerType, ?string $ownerId = null, string $slug = 'home'): Model
    {
        $model = $this->pageModelClass($ownerType);

        $query = $model::query()->where('owner_type', $ownerType);

        if ($ownerType === CentralSitePage::OWNER_PLATFORM) {
            $query->whereNull('owner_id');
        }

        $page = $query->where('slug', $slug)->first();

        if ($page instanceof Model) {
            return $page;
        }

        return $model::query()->create([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'slug' => $slug,
            'status' => CentralSitePage::STATUS_DRAFT,
        ]);
    }

    /**
     * Save a draft revision. Does NOT change the published version.
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>|null  $seo
     */
    public function saveDraft(Model $page, string $locale, array $content, ?string $title = null, ?array $seo = null, ?string $actorId = null): Model
    {
        $revisionClass = $this->revisionModelClass($page);

        return $revisionClass::query()->create([
            'page_id' => $page->getKey(),
            'locale' => $locale,
            'title' => $title,
            'content' => $content,
            'seo' => $seo,
            'status' => CentralSitePageRevision::STATUS_DRAFT,
            'created_by' => $actorId,
        ]);
    }

    /**
     * Publish the latest draft for a locale. Sets the page's current_revision_id
     * and marks the page as published. Fail-closed: returns null if no draft
     * exists for that locale.
     */
    public function publish(Model $page, string $locale, ?string $actorId = null): ?Model
    {
        $revisionClass = $this->revisionModelClass($page);

        $draft = $revisionClass::query()
            ->where('page_id', $page->getKey())
            ->where('locale', $locale)
            ->where('status', CentralSitePageRevision::STATUS_DRAFT)
            ->orderByDesc('created_at')
            ->first();

        if (! $draft instanceof Model) {
            return null;
        }

        $draft->update(['status' => CentralSitePageRevision::STATUS_PUBLISHED]);

        $page->update([
            'current_revision_id' => $draft->getKey(),
            'status' => CentralSitePage::STATUS_PUBLISHED,
            'updated_by' => $actorId,
        ]);

        $this->audit(self::ACTION_PUBLISHED, $page, $actorId, ['locale' => $locale]);

        return $draft->refresh();
    }

    /**
     * Get the published revision content for a locale. Returns null if no
     * published version exists (fail-closed for public rendering).
     *
     * @return array<string, mixed>|null
     */
    public function getPublished(Model $page, string $locale): ?array
    {
        if (! $page->isPublished()) {
            return null;
        }

        $revisionClass = $this->revisionModelClass($page);

        $revision = $revisionClass::query()
            ->where('page_id', $page->getKey())
            ->where('locale', $locale)
            ->where('status', CentralSitePageRevision::STATUS_PUBLISHED)
            ->orderByDesc('created_at')
            ->first();

        if (! $revision instanceof Model) {
            return null;
        }

        return [
            'title' => $revision->title,
            'content' => $revision->content,
            'seo' => $revision->seo,
        ];
    }

    /**
     * Get the latest draft revision for a locale (for editor preview).
     *
     * @return array<string, mixed>|null
     */
    public function getDraft(Model $page, string $locale): ?array
    {
        $revisionClass = $this->revisionModelClass($page);

        $revision = $revisionClass::query()
            ->where('page_id', $page->getKey())
            ->where('locale', $locale)
            ->where('status', CentralSitePageRevision::STATUS_DRAFT)
            ->orderByDesc('created_at')
            ->first();

        if (! $revision instanceof Model) {
            return null;
        }

        return [
            'title' => $revision->title,
            'content' => $revision->content,
            'seo' => $revision->seo,
        ];
    }

    /**
     * Restore an old revision by creating a new DRAFT with its content. The
     * editor must then publish the restored draft.
     */
    public function restoreRevision(Model $page, string $revisionId, ?string $actorId = null): ?Model
    {
        $revisionClass = $this->revisionModelClass($page);

        $source = $revisionClass::query()
            ->where('page_id', $page->getKey())
            ->whereKey($revisionId)
            ->first();

        if (! $source instanceof Model) {
            return null;
        }

        $restored = $revisionClass::query()->create([
            'page_id' => $page->getKey(),
            'locale' => $source->locale,
            'title' => $source->title,
            'content' => $source->content,
            'seo' => $source->seo,
            'status' => CentralSitePageRevision::STATUS_DRAFT,
            'created_by' => $actorId,
        ]);

        $this->audit(self::ACTION_RESTORED, $page, $actorId, [
            'locale' => $source->locale,
            'restored_from' => $revisionId,
        ]);

        return $restored;
    }

    /**
     * List all revisions for a page (newest first), across all locales.
     *
     * @return Collection<int, Model>
     */
    public function listRevisions(Model $page)
    {
        return $page->revisions;
    }

    private function pageModelClass(string $ownerType): string
    {
        return $ownerType === CentralSitePage::OWNER_PLATFORM
            ? CentralSitePage::class
            : TenantSitePage::class;
    }

    private function revisionModelClass(Model $page): string
    {
        return $page instanceof TenantSitePage
            ? TenantSitePageRevision::class
            : CentralSitePageRevision::class;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(string $action, Model $page, ?string $actorId, array $metadata = []): void
    {
        $scope = $page instanceof TenantSitePage ? 'tenant' : 'platform';
        $scopeKey = $page instanceof TenantSitePage ? (string) ($page->owner_type ?? 'tenant') : null;

        ModuleAuditLog::query()->create([
            'module_key' => self::MODULE_KEY,
            'action' => $action,
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'scope' => $scope,
            'scope_key' => $scopeKey,
            'actor_id' => $actorId,
            'metadata' => $metadata,
        ]);
    }
}
