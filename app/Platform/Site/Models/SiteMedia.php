<?php

namespace App\Platform\Site\Models;

use App\Models\User;
use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A CMS media asset (image/logo) owned by the platform website (central DB).
 *
 * The file lives on the dedicated, NON web-served CMS disk; this row records
 * disk/path/mime/size so the asset can be deleted with its row and so the
 * public URL is always the published-gated media endpoint — never the private
 * medical storage.
 */
#[Fillable([
    'owner_type',
    'owner_id',
    'disk',
    'path',
    'mime_type',
    'size_bytes',
    'alt_text',
    'created_by',
])]
class SiteMedia extends CentralModel
{
    use HasUlids;

    protected $table = 'site_media';

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The published-gated public URL of the asset.
     */
    public function url(): string
    {
        return route('site.media.show', ['media' => $this->getKey()]);
    }
}
