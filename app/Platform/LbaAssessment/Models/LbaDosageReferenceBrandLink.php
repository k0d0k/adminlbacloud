<?php

namespace App\Platform\LbaAssessment\Models;

use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referensi Rumusan Dosis — one Brand/SKU link of a source version (CENTRAL
 * database).
 *
 * A single imported source version may back MORE THAN ONE brand/SKU once its
 * product mapping is confirmed (Babul Syiffa, NuBiom and Biodam reuse the same
 * version). This is commercial linking metadata only: it carries NO patient,
 * assessment or examiner data, and `brand_id` is a plain central brand id
 * (no cross-database foreign key).
 */
#[Fillable([
    'reference_version_id',
    'brand_key',
    'brand_id',
    'linked_at',
])]
class LbaDosageReferenceBrandLink extends CentralModel
{
    use HasUlids;

    protected $table = 'lba_dosage_reference_brand_links';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LbaDosageReferenceVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(LbaDosageReferenceVersion::class, 'reference_version_id');
    }
}
