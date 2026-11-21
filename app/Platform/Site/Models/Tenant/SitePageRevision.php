<?php

namespace App\Platform\Site\Models\Tenant;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single revision of a tenant website page (tenant DB). Fails closed without
 * a verified tenant context.
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
class SitePageRevision extends TenantModel
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
}
