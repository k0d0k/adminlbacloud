<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An audited correction to a FINAL clinical encounter (Phase 3D).
 *
 * Append-only. A finalized note is never edited in place; the only way to
 * change it is to append an amendment, which MUST carry a reason and stores
 * both the previous and the new values (`changes`) together with the resulting
 * `revision`. The superseded content therefore stays recoverable even though
 * the encounter row shows the corrected version.
 */
#[Fillable([
    'encounter_id',
    'amended_by',
    'reason',
    'changes',
    'revision',
    'occurred_at',
])]
class ClinicalEncounterAmendment extends TenantModel
{
    use HasUlids;

    protected $table = 'clinical_encounter_amendments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'revision' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ClinicalEncounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }
}
