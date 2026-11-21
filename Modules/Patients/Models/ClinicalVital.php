<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A set of vital signs and anthropometry for one encounter (Phase 3A).
 *
 * One row per measurement set, so a SINGLE encounter can hold several readings
 * (e.g. a first blood pressure and a repeat a few minutes later) without
 * overwriting either. Each row carries the measurement context a clinician
 * needs to interpret the number: patient position, arm side, cuff size, device
 * and the time it was taken.
 *
 * Every value is independently nullable and `status` makes "not measured"
 * explicit, so a missing value is never stored — or displayed — as 0. `bmi` is
 * derived by the recording service only when both weight and height are present.
 */
#[Fillable([
    'encounter_id',
    'patient_id',
    'measured_at',
    'position',
    'arm',
    'cuff_size',
    'device',
    'systolic',
    'diastolic',
    'pulse',
    'respiratory_rate',
    'temperature_c',
    'oxygen_saturation',
    'weight_kg',
    'height_cm',
    'notes',
    'source',
])]
class ClinicalVital extends TenantModel
{
    use HasUlids;

    /** At least one measurement was recorded. */
    public const STATUS_MEASURED = 'measured';

    /** The set exists but nothing was actually measured. */
    public const STATUS_NOT_MEASURED = 'not_measured';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_MEASURED,
        self::STATUS_NOT_MEASURED,
    ];

    public const POSITION_SITTING = 'sitting';

    public const POSITION_SUPINE = 'supine';

    public const POSITION_STANDING = 'standing';

    /** @var list<string> */
    public const POSITIONS = [
        self::POSITION_SITTING,
        self::POSITION_SUPINE,
        self::POSITION_STANDING,
    ];

    public const ARM_LEFT = 'left';

    public const ARM_RIGHT = 'right';

    /** @var list<string> */
    public const ARMS = [
        self::ARM_LEFT,
        self::ARM_RIGHT,
    ];

    protected $table = 'clinical_vitals';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
            'systolic' => 'integer',
            'diastolic' => 'integer',
            'pulse' => 'integer',
            'respiratory_rate' => 'integer',
            'temperature_c' => 'decimal:1',
            'oxygen_saturation' => 'integer',
            'weight_kg' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'bmi' => 'decimal:2',
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

    public function hasBloodPressure(): bool
    {
        return $this->systolic !== null && $this->diastolic !== null;
    }

    /**
     * A display-safe blood-pressure string that never renders a missing value
     * as a number.
     */
    public function bloodPressureLabel(): ?string
    {
        if (! $this->hasBloodPressure()) {
            return null;
        }

        return $this->systolic.'/'.$this->diastolic;
    }
}
