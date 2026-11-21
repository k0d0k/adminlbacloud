<?php

namespace Modules\LBA\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * Referensi Rumusan Dosis — explicit indicator -> Lampiran A mapping (TENANT).
 *
 * A reviewed name equivalence between a digital indicator (plus an optional
 * recorded/confirmed condition) and a Lampiran A entry number. A label whose
 * equivalence has not been reviewed (a combined or ambiguous label) is stored
 * with `review_status = needs_mapping` and is surfaced to the examiner as
 * "Perlu Pemetaan" rather than silently guessed.
 */
#[Fillable([
    'indicator_key',
    'condition_label',
    'reference_entry_number',
    'reference_key',
    'review_status',
    'notes',
])]
class DosageIndicatorMapping extends TenantModel
{
    use HasUlids;

    /** A reviewed name equivalence — safe to use for selection. */
    public const REVIEWED = 'reviewed';

    /** Combined / ambiguous label that still requires mapping. */
    public const NEEDS_MAPPING = 'needs_mapping';

    protected $table = 'lba_dosage_indicator_mappings';
}
