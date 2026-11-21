<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Patients\Support\RecordsClinicalProvenance;

/**
 * A medication, herbal product or supplement the patient reports taking
 * (Phase 2D).
 *
 * This is a REPORTED-CONSUMPTION list, never a prescription: recording a row
 * here creates no order, no prescription and no administration instruction.
 * Covers prescription drugs, over-the-counter products, herbal products and
 * supplements alike, since the clinical question at a visit is "what is this
 * patient actually taking?".
 */
#[Fillable([
    'patient_id',
    'name',
    'strength',
    'dose',
    'route',
    'frequency',
    'indication',
    'started_on',
    'ended_on',
    'status',
    'source',
    'notes',
])]
class PatientMedication extends TenantModel
{
    use HasUlids;
    use RecordsClinicalProvenance;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_UNKNOWN = 'unknown';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_STOPPED,
        self::STATUS_UNKNOWN,
    ];

    protected $table = 'patient_medications';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'ended_on' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @param  Builder<PatientMedication>  $query
     * @return Builder<PatientMedication>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
