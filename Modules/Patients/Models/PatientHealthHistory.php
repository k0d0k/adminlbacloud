<?php

namespace Modules\Patients\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Patients\Support\RecordsClinicalProvenance;

/**
 * Supporting health history in a patient's profile (Phase 2E).
 *
 * One categorised table covers past surgery, hospitalisation, relevant family
 * history and lifestyle context (smoking, alcohol, activity, diet, sleep,
 * pregnancy/lactation). Categories are values, not tables, so adding one never
 * needs a schema change.
 *
 * Only the minimum needed for care is stored: family history records the
 * relationship and the condition, not the relatives' identities.
 */
#[Fillable([
    'patient_id',
    'category',
    'title',
    'detail',
    'occurred_on',
    'source',
])]
class PatientHealthHistory extends TenantModel
{
    use HasUlids;
    use RecordsClinicalProvenance;

    public const CATEGORY_SURGERY = 'surgery';

    public const CATEGORY_HOSPITALIZATION = 'hospitalization';

    public const CATEGORY_FAMILY = 'family';

    public const CATEGORY_SMOKING = 'smoking';

    public const CATEGORY_ALCOHOL = 'alcohol';

    public const CATEGORY_ACTIVITY = 'activity';

    public const CATEGORY_DIET = 'diet';

    public const CATEGORY_SLEEP = 'sleep';

    public const CATEGORY_PREGNANCY = 'pregnancy';

    public const CATEGORY_OTHER = 'other';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_SURGERY,
        self::CATEGORY_HOSPITALIZATION,
        self::CATEGORY_FAMILY,
        self::CATEGORY_SMOKING,
        self::CATEGORY_ALCOHOL,
        self::CATEGORY_ACTIVITY,
        self::CATEGORY_DIET,
        self::CATEGORY_SLEEP,
        self::CATEGORY_PREGNANCY,
        self::CATEGORY_OTHER,
    ];

    protected $table = 'patient_health_histories';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
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
}
