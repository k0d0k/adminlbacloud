<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail entry for a clinically meaningful write (Phase 6).
 *
 * Append-only. One row per action, pointing at the affected subject
 * (`subject_type` + `subject_id`) and, where known, the patient the trail
 * belongs to. `changes` carries only what is needed to answer "who changed
 * what, when" — never credentials and never any clinical text the operator did
 * not explicitly record.
 */
#[Fillable([
    'patient_id',
    'subject_type',
    'subject_id',
    'action',
    'actor_id',
    'changes',
    'occurred_at',
])]
class ClinicalAuditLog extends TenantModel
{
    use HasUlids;

    /** A profile entry (condition, allergy, medication, history) was added. */
    public const ACTION_PROFILE_ENTRY_CREATED = 'profile_entry_created';

    /** A profile entry was edited. */
    public const ACTION_PROFILE_ENTRY_UPDATED = 'profile_entry_updated';

    /** A profile entry was reviewed and confirmed by an authorized person. */
    public const ACTION_PROFILE_ENTRY_VERIFIED = 'profile_entry_verified';

    /** The patient-level three-valued allergy status was asserted. */
    public const ACTION_ALLERGY_STATUS_SET = 'allergy_status_set';

    /** A new clinical encounter was opened. */
    public const ACTION_ENCOUNTER_OPENED = 'encounter_opened';

    /** A draft clinical encounter was edited. */
    public const ACTION_ENCOUNTER_UPDATED = 'encounter_updated';

    /** A clinical encounter was signed off. */
    public const ACTION_ENCOUNTER_FINALIZED = 'encounter_finalized';

    /** A finalized clinical encounter was corrected through an amendment. */
    public const ACTION_ENCOUNTER_AMENDED = 'encounter_amended';

    /** A set of vital signs / anthropometry was recorded. */
    public const ACTION_VITALS_RECORDED = 'vitals_recorded';

    /** A blood-glucose or HbA1c reading was recorded. */
    public const ACTION_GLUCOSE_RECORDED = 'glucose_recorded';

    /** A supporting laboratory result was recorded. */
    public const ACTION_LAB_RESULT_RECORDED = 'lab_result_recorded';

    protected $table = 'clinical_audit_logs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
