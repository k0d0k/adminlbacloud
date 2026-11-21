<?php

namespace Modules\Appointments\Models;

use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Patients\Models\Patient;

/**
 * Phase 10 — a patient's place on a location's booking waitlist.
 *
 * Created when the requested window is at capacity instead of refusing the
 * patient. The entry moves `waiting` → `offered` when a slot frees, then
 * either `converted` into a real appointment or `expired`/`cancelled`.
 */
#[Fillable([
    'branch_id',
    'patient_id',
    'service_type',
    'desired_starts_at',
    'desired_ends_at',
    'status',
    'offered_at',
    'expires_at',
    'note',
    'created_by',
])]
class WaitlistEntry extends TenantModel
{
    use HasUlids;

    public const STATUS_WAITING = 'waiting';

    public const STATUS_OFFERED = 'offered';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_WAITING,
        self::STATUS_OFFERED,
        self::STATUS_CONVERTED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    /** Statuses that still hold a place in the queue. */
    public const ACTIVE_STATUSES = [
        self::STATUS_WAITING,
        self::STATUS_OFFERED,
    ];

    protected $table = 'waitlist_entries';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'desired_starts_at' => 'datetime',
            'desired_ends_at' => 'datetime',
            'offered_at' => 'datetime',
            'expires_at' => 'datetime',
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

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * An offer is only valid until its expiry moment.
     */
    public function offerIsLive(): bool
    {
        return $this->status === self::STATUS_OFFERED
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
