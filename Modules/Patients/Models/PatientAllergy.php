<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Patients\Support\RecordsClinicalProvenance;

/**
 * An allergy or intolerance in a patient's profile (Phase 2C).
 *
 * One row per substance (multi-entry). `severity` includes `unknown`, which is
 * a real clinical answer and must not be displayed as "mild"; an unassessed
 * reaction is not a graded one.
 *
 * These rows are what drive the safety alerts shown at the top of the patient
 * profile and whenever a clinician opens an encounter, so they are read on
 * every visit — but the overall "does this patient have allergies at all?"
 * answer lives on the patient (`allergy_status`), because an empty list and
 * "we never asked" are different statements.
 */
#[Fillable([
    'patient_id',
    'substance',
    'category',
    'reaction',
    'severity',
    'occurred_on',
    'is_active',
    'source',
    'notes',
])]
class PatientAllergy extends TenantModel
{
    use HasUlids;
    use RecordsClinicalProvenance;

    public const CATEGORY_MEDICATION = 'medication';

    public const CATEGORY_FOOD = 'food';

    public const CATEGORY_OTHER = 'other';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_MEDICATION,
        self::CATEGORY_FOOD,
        self::CATEGORY_OTHER,
    ];

    public const SEVERITY_MILD = 'mild';

    public const SEVERITY_MODERATE = 'moderate';

    public const SEVERITY_SEVERE = 'severe';

    public const SEVERITY_UNKNOWN = 'unknown';

    /** @var list<string> */
    public const SEVERITIES = [
        self::SEVERITY_MILD,
        self::SEVERITY_MODERATE,
        self::SEVERITY_SEVERE,
        self::SEVERITY_UNKNOWN,
    ];

    protected $table = 'patient_allergies';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'is_active' => 'boolean',
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

    /**
     * @param  Builder<PatientAllergy>  $query
     * @return Builder<PatientAllergy>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
