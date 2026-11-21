<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A blood-glucose or HbA1c reading for one encounter (Phase 3B).
 *
 * `kind` is explicit because fasting, random and post-meal glucose are
 * different measurements that must never be plotted as one series, and HbA1c is
 * a different test entirely.
 *
 * The value the operator entered is stored VERBATIM (`value` + `unit`) and a
 * derived `value_normalized` (+ `normalized_unit`) is stored alongside so
 * charts and comparisons can use one canonical unit WITHOUT ever rewriting the
 * original record. The conversion is performed and validated by the recording
 * service.
 *
 * `value` is nullable and `status` distinguishes "not performed" from a
 * measured value: an unperformed test is stored — and displayed — as "not
 * performed", never as 0.
 */
#[Fillable([
    'encounter_id',
    'patient_id',
    'kind',
    'value',
    'unit',
    'specimen',
    'method',
    'measured_at',
    'last_meal_at',
    'source_name',
    'notes',
])]
class ClinicalGlucoseReading extends TenantModel
{
    use HasUlids;

    public const KIND_FASTING = 'fasting';

    public const KIND_RANDOM = 'random';

    public const KIND_POST_MEAL = 'post_meal';

    public const KIND_HBA1C = 'hba1c';

    /** @var list<string> */
    public const KINDS = [
        self::KIND_FASTING,
        self::KIND_RANDOM,
        self::KIND_POST_MEAL,
        self::KIND_HBA1C,
    ];

    /** @var list<string> */
    public const GLUCOSE_KINDS = [
        self::KIND_FASTING,
        self::KIND_RANDOM,
        self::KIND_POST_MEAL,
    ];

    /** At least one measurement was recorded. */
    public const STATUS_MEASURED = 'measured';

    /** The test was explicitly not performed. */
    public const STATUS_NOT_PERFORMED = 'not_performed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_MEASURED,
        self::STATUS_NOT_PERFORMED,
    ];

    /** Canonical unit every glucose value is normalised to. */
    public const CANONICAL_GLUCOSE_UNIT = 'mg/dL';

    /** HbA1c is a percentage, never a glucose concentration. */
    public const HBA1C_UNIT = '%';

    protected $table = 'clinical_glucose_readings';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:3',
            'value_normalized' => 'decimal:3',
            'measured_at' => 'datetime',
            'last_meal_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ClinicalEncounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function isMeasured(): bool
    {
        return $this->status === self::STATUS_MEASURED && $this->value !== null;
    }

    public function isHbA1c(): bool
    {
        return $this->kind === self::KIND_HBA1C;
    }
}
