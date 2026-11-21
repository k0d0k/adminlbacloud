<?php

namespace App\Platform\LbaAssessment\Models;

use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referensi Rumusan Dosis — one PHASE value of a Lampiran A entry (CENTRAL db).
 *
 * `dosage_notation` preserves the ORIGINAL notation (`4 X 2`); the structured
 * columns are the reading of "A x B" (A sachet per intake, B intakes per day).
 * The four phases map exactly to the assessment program phases, so a selection
 * can read the value for the phase the examiner works in.
 */
#[Fillable([
    'reference_entry_id',
    'phase',
    'dosage_notation',
    'sachets_per_intake',
    'intakes_per_day',
    'sachets_per_day',
])]
class LbaDosageReferencePhaseValue extends CentralModel
{
    use HasUlids;

    public const PHASE_CLEANSING = 'cleansing';

    public const PHASE_BALANCING = 'balancing';

    public const PHASE_PROTECTING = 'protecting';

    public const PHASE_MAINTENANCE = 'maintenance';

    /** @var list<string> */
    public const PHASES = [
        self::PHASE_CLEANSING,
        self::PHASE_BALANCING,
        self::PHASE_PROTECTING,
        self::PHASE_MAINTENANCE,
    ];

    protected $table = 'lba_dosage_reference_phase_values';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sachets_per_intake' => 'integer',
            'intakes_per_day' => 'integer',
            'sachets_per_day' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LbaDosageReferenceEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(LbaDosageReferenceEntry::class, 'reference_entry_id');
    }
}
