<?php

namespace App\Platform\LbaAssessment\Models;

use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Referensi Rumusan Dosis — one numbered Lampiran A entry of a source version
 * (CENTRAL database).
 *
 * `entry_number` (`001`..`226`), the original `condition_name` and the
 * `tambahan_text` are preserved verbatim. A phase value (Cleansing / Balancing /
 * Protecting / Maintenance) hangs off every entry via {@see phaseValues()}.
 */
#[Fillable([
    'reference_version_id',
    'entry_number',
    'condition_name',
    'tambahan_text',
    'position',
])]
class LbaDosageReferenceEntry extends CentralModel
{
    use HasUlids;

    protected $table = 'lba_dosage_reference_entries';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LbaDosageReferenceVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(LbaDosageReferenceVersion::class, 'reference_version_id');
    }

    /**
     * @return HasMany<LbaDosageReferencePhaseValue, $this>
     */
    public function phaseValues(): HasMany
    {
        return $this->hasMany(LbaDosageReferencePhaseValue::class, 'reference_entry_id')->orderBy('phase');
    }

    public function phaseValue(string $phase): ?LbaDosageReferencePhaseValue
    {
        return $this->phaseValues()->where('phase', $phase)->first();
    }
}
