<?php

namespace App\Platform\LbaAssessment\Models;

use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Referensi Rumusan Dosis — one imported SOURCE VERSION of a dosage master
 * (CENTRAL database).
 *
 * Central, non-patient reference metadata (a DRAF INTERNAL master). It is the
 * dosage-reference layer the examiner surface reads against and NEVER holds a
 * patient finding, an examiner decision or an assessment result — those live in
 * the tenant database. The `key` is a stable master identifier and `version`
 * increments when a changed source file is imported, so a historical decision
 * (which snapshots the version key) stays traceable after the file changes.
 */
#[Fillable([
    'key',
    'version',
    'label',
    'status',
    'brand_id',
    'brand_key',
    'source_file',
    'source_checksum',
    'entry_count',
    'phase_value_count',
    'tambahan_count',
    'notes',
    'metadata',
    'imported_at',
    'imported_by',
])]
class LbaDosageReferenceVersion extends CentralModel
{
    use HasUlids;

    /** Imported from a source file but NOT yet clinically confirmed for use. */
    public const STATUS_DRAFT = 'draft';

    /** Confirmed / in force for its brand link. */
    public const STATUS_ACTIVE = 'active';

    /** Superseded by a newer version; kept for traceability. */
    public const STATUS_RETIRED = 'retired';

    protected $table = 'lba_dosage_reference_versions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'entry_count' => 'integer',
            'phase_value_count' => 'integer',
            'tambahan_count' => 'integer',
            'metadata' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<LbaDosageReferenceEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LbaDosageReferenceEntry::class, 'reference_version_id')->orderBy('position');
    }

    /**
     * The Brand/SKU links of this version. One version may back more than one
     * brand once its product mapping is confirmed.
     *
     * @return HasMany<LbaDosageReferenceBrandLink, $this>
     */
    public function brandLinks(): HasMany
    {
        return $this->hasMany(LbaDosageReferenceBrandLink::class, 'reference_version_id')->orderBy('brand_key');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
