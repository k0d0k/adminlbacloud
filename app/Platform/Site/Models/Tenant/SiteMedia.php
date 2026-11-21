<?php

namespace App\Platform\Site\Models\Tenant;

use App\Models\Tenant;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A CMS media asset owned by a tenant website (tenant DB). Fails closed without
 * a verified tenant context. The file is stored on the dedicated, NON web-served
 * CMS disk under the tenant's own prefix and is NEVER written to the private
 * medical disks.
 */
#[Fillable([
    'disk',
    'path',
    'mime_type',
    'size_bytes',
    'alt_text',
    'created_by',
])]
class SiteMedia extends TenantModel
{
    use HasUlids;

    protected $table = 'site_media';

    /**
     * The published-gated public URL of the asset, resolved for the CURRENT
     * tenant context (the tenant slug is part of the tenant media route).
     */
    public function url(): string
    {
        $tenantId = app(ResolvesTenantDatabase::class)->current();

        $slug = $tenantId !== null
            ? Tenant::query()->whereKey($tenantId)->value('slug')
            : null;

        return route('tenant.site.media.show', [
            'slug' => (string) $slug,
            'media' => $this->getKey(),
        ]);
    }
}
