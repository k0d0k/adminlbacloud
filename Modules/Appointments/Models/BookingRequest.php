<?php

namespace Modules\Appointments\Models;

use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Appointments\Services\AppointmentScheduler;

/**
 * A public visitor booking request of one tenant (Website CMS, Task 11).
 *
 * Extends {@see TenantModel}, so it resolves the ACTIVE tenant database at query
 * time and fails closed without a verified tenant context — a request can never
 * be read from, or written to, the central database or another tenant's
 * database.
 *
 * A request is INTENT, not a schedule: it becomes an appointment only after the
 * visitor verifies their email and confirms it in the patient portal, where the
 * slot is re-validated and the appointment is created through
 * {@see AppointmentScheduler}. `appointment_id`
 * therefore stays null until conversion, and conversion is idempotent.
 */
#[Fillable([
    'full_name',
    'email',
    'phone',
    'branch_id',
    'service_type',
    'scheduled_at',
    'status',
    'verification_token_hash',
    'verification_expires_at',
    'privacy_consent_at',
    'patient_id',
    'appointment_id',
])]
class BookingRequest extends TenantModel
{
    use HasUlids;

    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING_VERIFICATION,
        self::STATUS_VERIFIED,
        self::STATUS_CONVERTED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Statuses a visitor may still confirm in the portal: the email has been
     * proven and no appointment has been created yet.
     *
     * @var list<string>
     */
    public const CONFIRMABLE_STATUSES = [
        self::STATUS_VERIFIED,
    ];

    protected $table = 'booking_requests';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'verification_expires_at' => 'datetime',
            'privacy_consent_at' => 'datetime',
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
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function isPendingVerification(): bool
    {
        return $this->status === self::STATUS_PENDING_VERIFICATION;
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function isConverted(): bool
    {
        return $this->status === self::STATUS_CONVERTED && $this->appointment_id !== null;
    }

    /**
     * Whether the verification link is still within its TTL.
     */
    public function verificationHasExpired(): bool
    {
        return $this->verification_expires_at !== null
            && $this->verification_expires_at->isPast();
    }

    /**
     * @param  Builder<BookingRequest>  $query
     * @return Builder<BookingRequest>
     */
    public function scopeConfirmable(Builder $query): Builder
    {
        return $query->whereIn('status', self::CONFIRMABLE_STATUSES);
    }

    /**
     * @param  Builder<BookingRequest>  $query
     * @return Builder<BookingRequest>
     */
    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->whereRaw('lower(email) = ?', [strtolower(trim($email))]);
    }
}
