<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One clinical encounter — a single visit record (Phase 3).
 *
 * A patient has MANY encounters and an encounter NEVER overwrites an earlier
 * one. Vitals, glucose readings and lab results each hang off their own
 * encounter row, so the longitudinal record is preserved by construction.
 *
 * Lifecycle: `draft` while an authorized person is still writing, `final` once
 * signed off, `amended` once a final record has been corrected. A final record
 * is never silently rewritten — the ONLY path that changes it is
 * {@see ClinicalEncounterAmendment}, which always stores a reason and the
 * previous values.
 *
 * SOAP is stored as four explicit fields so the objective findings can be told
 * apart from the clinical assessment. The clinical narrative fields
 * (subjective/objective/assessment/plan) and the working fields
 * (chief_complaint … supporting_findings) are nullable by design: an encounter
 * may legitimately be recorded with only some parts completed.
 *
 * Finalizing and amending are NOT mass-assignable — only the authorized,
 * audited clinical service performs them.
 */
#[Fillable([
    'patient_id',
    'branch_id',
    'appointment_id',
    'practitioner_id',
    'encounter_at',
    'timezone',
    'type',
    'chief_complaint',
    'history_of_present_illness',
    'reviewed_history',
    'physical_exam',
    'supporting_findings',
    'subjective',
    'objective',
    'assessment',
    'plan',
])]
class ClinicalEncounter extends TenantModel
{
    use HasUlids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINAL = 'final';

    public const STATUS_AMENDED = 'amended';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_FINAL,
        self::STATUS_AMENDED,
    ];

    public const TYPE_NEW = 'new';

    public const TYPE_FOLLOW_UP = 'follow_up';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_NEW,
        self::TYPE_FOLLOW_UP,
    ];

    protected $table = 'clinical_encounters';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'encounter_at' => 'datetime',
            'reviewed_history' => 'array',
            'finalized_at' => 'datetime',
            'amended_at' => 'datetime',
            'revision' => 'integer',
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
     * @return HasMany<ClinicalVital, $this>
     */
    public function vitals(): HasMany
    {
        return $this->hasMany(ClinicalVital::class, 'encounter_id');
    }

    /**
     * @return HasMany<ClinicalGlucoseReading, $this>
     */
    public function glucoseReadings(): HasMany
    {
        return $this->hasMany(ClinicalGlucoseReading::class, 'encounter_id');
    }

    /**
     * @return HasMany<ClinicalLabResult, $this>
     */
    public function labResults(): HasMany
    {
        return $this->hasMany(ClinicalLabResult::class, 'encounter_id');
    }

    /**
     * @return HasMany<ClinicalEncounterAmendment, $this>
     */
    public function amendments(): HasMany
    {
        return $this->hasMany(ClinicalEncounterAmendment::class, 'encounter_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isFinal(): bool
    {
        return $this->status === self::STATUS_FINAL;
    }

    /**
     * True once a final record has been corrected: it stays readable but must
     * be presented alongside its amendment history.
     */
    public function isAmended(): bool
    {
        return $this->status === self::STATUS_AMENDED;
    }

    /**
     * True while the clinical narrative may still be written directly. A final
     * or amended encounter is frozen and may only be corrected through an
     * audited amendment.
     */
    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    /**
     * @param  Builder<ClinicalEncounter>  $query
     * @return Builder<ClinicalEncounter>
     */
    public function scopeForPatient(Builder $query, string $patientId): Builder
    {
        return $query->where('patient_id', $patientId);
    }
}
