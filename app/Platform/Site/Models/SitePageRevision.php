<?php

namespace App\Platform\Site\Models;

use App\Models\User;
use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single revision of a platform website page (central DB).
 *
 * Each publish/draft creates a new revision row so the full history is
 * retained and any revision can be restored. Content is a JSON array of
 * sections; seo holds title/description/OG metadata.
 */
#[Fillable([
    'page_id',
    'locale',
    'title',
    'content',
    'seo',
    'status',
    'created_by',
])]
class SitePageRevision extends CentralModel
{
    use HasUlids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $table = 'site_page_revisions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'seo' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SitePage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(SitePage::class, 'page_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
