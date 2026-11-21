<?php

namespace App\Platform\Site\Models\Tenant;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A website page owned by a TENANT (clinic website), stored inside the tenant's
 * isolated database. Fails closed without a verified tenant context.
 *
 * There is no owner_id column: the tenant IS the owner (the database is the
 * tenant boundary, ARCH-05). Two tenants sharing a brand therefore keep their
 * own page, revisions and media.
 */
#[Fillable([
    'owner_type',
    'slug',
    'status',
    'current_revision_id',
    'settings',
    'created_by',
    'updated_by',
])]
class SitePage extends TenantModel
{
    use HasUlids;

    public const OWNER_TENANT = 'tenant';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $table = 'site_pages';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * @return HasMany<SitePageRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(SitePageRevision::class, 'page_id')->orderByDesc('created_at');
    }

    /**
     * @return BelongsTo<SitePageRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(SitePageRevision::class, 'current_revision_id');
    }

    /**
     * @return HasMany<SiteMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(SiteMedia::class);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->current_revision_id !== null;
    }
}
