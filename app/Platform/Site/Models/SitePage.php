<?php

namespace App\Platform\Site\Models;

use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A website page owned by the PLATFORM (central DB).
 *
 * One row per owner (the Principal website has owner_type='platform',
 * owner_id=null). Holds the current published revision pointer and display
 * settings; per-locale content lives in {@see SitePageRevision}.
 */
#[Fillable([
    'owner_type',
    'owner_id',
    'slug',
    'status',
    'current_revision_id',
    'settings',
    'created_by',
    'updated_by',
])]
class SitePage extends CentralModel
{
    use HasUlids;

    public const OWNER_PLATFORM = 'platform';

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
        return $this->hasMany(SiteMedia::class, 'owner_id')->where('owner_type', self::OWNER_PLATFORM);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->current_revision_id !== null;
    }
}
