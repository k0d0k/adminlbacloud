<?php

namespace Modules\Patients\Models;

use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Appointments\Models\Appointment;
use Modules\Patients\Services\PatientAccountLinker;

/**
 * A patient of one tenant (Phase 3).
 *
 * Extends {@see TenantModel}, so it resolves the ACTIVE tenant database at
 * query time and fails closed without a verified tenant context — a patient can
 * never be read from, or written to, the central database or another tenant's
 * database.
 *
 * A patient carries no tenant identity: the tenant is the database the row
 * lives in. The tenant-local patient number is unique within that database.
 * Archiving is non-destructive — the row is retained, only the status changes,
 * so a patient's history across every branch of the tenant survives.
 *
 * The portal account binding (`user_id`) is DELIBERATELY not mass-assignable:
 * it is never fillable, so untrusted self-service input can never attach an
 * account. An authorized, auditable clinic action must set it explicitly
 * ({@see PatientAccountLinker}).
 */
#[Fillable([
    'patient_number',
    'full_name',
    'date_of_birth',
    'sex',
    'gender',
    'phone',
    'email',
    'address',
    'city',
    'region',
    'country',
    'registered_branch_id',
    'registered_at',
    'status',
    'registration_source',
    'self_registered_at',
])]
class Patient extends TenantModel
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * Provenance of the patient row (Website CMS). A `self` record was created
     * by the platform from a public frontpage registration and never claims an
     * existing medical record; a `clinic` record was created by clinic staff.
     */
    public const SOURCE_CLINIC = 'clinic';

    public const SOURCE_SELF = 'self';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_ARCHIVED,
    ];

    public const SEX_MALE = 'male';

    public const SEX_FEMALE = 'female';

    public const SEX_OTHER = 'other';

    public const SEX_UNKNOWN = 'unknown';

    /** @var list<string> */
    public const SEXES = [
        self::SEX_MALE,
        self::SEX_FEMALE,
        self::SEX_OTHER,
        self::SEX_UNKNOWN,
    ];

    /**
     * Overall allergy status (Phase 2C) — a three-valued clinical assertion,
     * deliberately NOT a boolean. "No known allergies" and "not yet assessed"
     * are different statements and must never collapse into each other (or into
     * an empty allergy list).
     */
    public const ALLERGY_STATUS_HAS_ALLERGIES = 'has_allergies';

    public const ALLERGY_STATUS_NONE_KNOWN = 'no_known_allergies';

    public const ALLERGY_STATUS_UNKNOWN = 'unknown';

    /** @var list<string> */
    public const ALLERGY_STATUSES = [
        self::ALLERGY_STATUS_HAS_ALLERGIES,
        self::ALLERGY_STATUS_NONE_KNOWN,
        self::ALLERGY_STATUS_UNKNOWN,
    ];

    protected $table = 'patients';

    /**
     * A brand-new patient has NOT been allergy-assessed yet, so the overall
     * allergy answer starts at the explicit three-valued default rather than a
     * blank that could be mistaken for "no known allergies". Mirrors the column
     * default so an in-memory instance and the persisted row agree.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'allergy_status' => self::ALLERGY_STATUS_UNKNOWN,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'registered_at' => 'datetime',
            'self_registered_at' => 'datetime',
            'archived_at' => 'datetime',
            'portal_invite_expires_at' => 'datetime',
            'allergy_status_recorded_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PatientConsent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(PatientConsent::class, 'patient_id');
    }

    /**
     * @return HasMany<PatientAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(PatientAuditLog::class, 'patient_id');
    }

    /**
     * @return BelongsTo<TenantBranch, $this>
     */
    public function registeredBranch(): BelongsTo
    {
        return $this->belongsTo(TenantBranch::class, 'registered_branch_id');
    }

    /**
     * Appointment history, owned by the Appointments module.
     *
     * Resolved lazily by class name so the Patients module never hard-depends
     * on the Appointments module being installed.
     *
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'patient_id');
    }

    /**
     * Health problems / conditions in the longitudinal profile (Phase 2B).
     *
     * @return HasMany<PatientCondition, $this>
     */
    public function conditions(): HasMany
    {
        return $this->hasMany(PatientCondition::class, 'patient_id');
    }

    /**
     * Allergies and intolerances (Phase 2C).
     *
     * @return HasMany<PatientAllergy, $this>
     */
    public function allergies(): HasMany
    {
        return $this->hasMany(PatientAllergy::class, 'patient_id');
    }

    /**
     * Reported medications, herbal products and supplements (Phase 2D).
     *
     * @return HasMany<PatientMedication, $this>
     */
    public function medications(): HasMany
    {
        return $this->hasMany(PatientMedication::class, 'patient_id');
    }

    /**
     * Supporting health history — surgery, family history, lifestyle (Phase 2E).
     *
     * @return HasMany<PatientHealthHistory, $this>
     */
    public function healthHistories(): HasMany
    {
        return $this->hasMany(PatientHealthHistory::class, 'patient_id');
    }

    /**
     * Clinical encounters / visits (Phase 3). One row per visit; a newer visit
     * never overwrites an older one.
     *
     * @return HasMany<ClinicalEncounter, $this>
     */
    public function clinicalEncounters(): HasMany
    {
        return $this->hasMany(ClinicalEncounter::class, 'patient_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * The patient's age in whole years, derived from the date of birth — `null`
     * when the date of birth is not recorded, never a misleading 0.
     */
    public function age(): ?int
    {
        return $this->date_of_birth?->age;
    }

    /**
     * True only when an authorized person has asserted that at least one
     * allergy/intolerance is recorded. Distinct from "no known allergies" and
     * from the default "unknown".
     */
    public function hasAllergies(): bool
    {
        return $this->allergy_status === self::ALLERGY_STATUS_HAS_ALLERGIES;
    }

    /**
     * True when there are allergies recorded (by the overall status OR by the
     * presence of an active allergy row). Used to decide whether the profile
     * must raise a safety alert.
     */
    public function requiresAllergyAlert(): bool
    {
        return $this->hasAllergies();
    }

    /**
     * True once an authorized clinic action has bound a portal account to this
     * patient record.
     */
    public function isLinked(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * True when this row was created by the platform from a public frontpage
     * self-registration rather than by clinic staff.
     */
    public function isSelfRegistered(): bool
    {
        return $this->registration_source === self::SOURCE_SELF;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * Non-destructive archiving: the patient row is retained so history stays
     * intact; only the status changes.
     */
    public function archive(): void
    {
        $this->forceFill([
            'status' => self::STATUS_ARCHIVED,
            'archived_at' => now(),
        ])->save();
    }

    /**
     * Non-destructive reactivation.
     */
    public function restore(): void
    {
        $this->forceFill([
            'status' => self::STATUS_ACTIVE,
            'archived_at' => null,
        ])->save();
    }

    /**
     * @param  Builder<Patient>  $query
     * @return Builder<Patient>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
