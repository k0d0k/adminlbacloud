<?php

namespace Modules\LBA\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referensi Rumusan Dosis — the per-phase reference selection for an assessment
 * (TENANT database).
 *
 * Internal review data: the resolved reference (or the set of candidates the
 * examiner must choose from), the reason for the pick, the frozen snapshot and
 * the examiner's final decision. It is NEVER published to the patient portal —
 * only a final instruction explicitly approved through the existing report flow
 * ever reaches a patient.
 */
#[Fillable([
    'assessment_id',
    'program_phase',
    'status',
    'indicator_key',
    'category',
    'reference_key',
    'reference_version',
    'reference_entry_number',
    'source_notation',
    'total_sachets_per_day',
    'reason',
    'considered',
    'snapshot',
    'decision',
    'decided_by',
    'decided_at',
    'examiner_notes',
])]
class AssessmentDosageSelection extends TenantModel
{
    use HasUlids;

    /** A single reference was chosen automatically within the rules. */
    public const STATUS_SELECTED = 'selected';

    /** More than one comparable candidate — the examiner must choose. */
    public const STATUS_MULTIPLE_CHOICES = 'multiple_choices';

    /** A red indicator whose reference is unmapped or still draft. */
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    /** A relevant indicator whose label is combined/ambiguous. */
    public const STATUS_NEEDS_MAPPING = 'needs_mapping';

    /** All indicators green or unassessed — no reference applies. */
    public const STATUS_NO_REFERENCE = 'no_reference';

    public const DECISION_PENDING = 'pending';

    public const DECISION_ACCEPTED = 'accepted';

    public const DECISION_OVERRIDDEN = 'overridden';

    protected $table = 'lba_assessment_dosage_selections';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reference_version' => 'integer',
            'total_sachets_per_day' => 'integer',
            'considered' => 'array',
            'snapshot' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }

    public function isPending(): bool
    {
        return $this->decision === self::DECISION_PENDING;
    }

    public function needsExaminerChoice(): bool
    {
        return in_array($this->status, [self::STATUS_MULTIPLE_CHOICES, self::STATUS_NEEDS_REVIEW, self::STATUS_NEEDS_MAPPING], true);
    }
}
