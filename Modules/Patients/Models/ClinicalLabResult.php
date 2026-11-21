<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supporting laboratory result (Phase 3C).
 *
 * Deliberately generic: a test name, a free-text result (so "Negatif" or
 * "< 0,5" are representable without lossy coercion), a unit, and the REFERENCE
 * RANGE THE LABORATORY SUPPLIED. There is no global "normal range" here — a
 * reference range depends on the method, the laboratory and the patient, so the
 * platform stores what the source reported instead of inventing one.
 *
 * Attachments are recorded as a private-disk reference (disk + object key),
 * never a public URL.
 */
#[Fillable([
    'patient_id',
    'encounter_id',
    'test_name',
    'result_value',
    'unit',
    'reference_range',
    'collected_at',
    'resulted_at',
    'source_name',
    'notes',
])]
class ClinicalLabResult extends TenantModel
{
    use HasUlids;

    /** At least one result was supplied. */
    public const STATUS_MEASURED = 'measured';

    /** The test was explicitly not performed. */
    public const STATUS_NOT_PERFORMED = 'not_performed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_MEASURED,
        self::STATUS_NOT_PERFORMED,
    ];

    protected $table = 'clinical_lab_results';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'resulted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * @return BelongsTo<ClinicalEncounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }
}
