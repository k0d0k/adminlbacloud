<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Patients\Support\RecordsClinicalProvenance;

/**
 * A health problem / condition in a patient's longitudinal profile (Phase 2B).
 *
 * Lives in the tenant's isolated clinical database and belongs to one patient.
 * A condition persists across visits: later encounters reference it instead of
 * re-recording it, so "hypertension since 2019" stays one row with its onset
 * date rather than being duplicated per visit.
 *
 * `icd10_code` is optional and only ever set by an authorized clinician — the
 * platform never codes a condition by itself, and an entry reported by the
 * patient is NOT a diagnosis ({@see RecordsClinicalProvenance}).
 */
#[Fillable([
    'patient_id',
    'name',
    'icd10_code',
    'onset_date',
    'onset_note',
    'status',
    'source',
    'notes',
])]
class PatientCondition extends TenantModel
{
    use HasUlids;
    use RecordsClinicalProvenance;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_UNKNOWN = 'unknown';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_RESOLVED,
        self::STATUS_UNKNOWN,
    ];

    protected $table = 'patient_conditions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'onset_date' => 'date',
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
     * @param  Builder<PatientCondition>  $query
     * @return Builder<PatientCondition>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
