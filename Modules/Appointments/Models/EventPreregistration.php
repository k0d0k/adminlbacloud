<?php

namespace Modules\Appointments\Models;

use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Patients\Models\Patient;

/**
 * Phase 10 — a patient's expressed interest in a TENTATIVE event.
 *
 * A tentative (unconfirmed temporary / event) location never confirms a
 * booking; it only accepts preregistration. The record is kept so the organiser
 * can notify everyone once the event is confirmed.
 */
#[Fillable([
    'branch_id',
    'patient_id',
    'status',
    'note',
    'confirmed_at',
    'cancelled_at',
])]
class EventPreregistration extends TenantModel
{
    use HasUlids;

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_REGISTERED,
        self::STATUS_CONFIRMED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'event_preregistrations';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TenantBranch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(TenantBranch::class, 'branch_id');
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
