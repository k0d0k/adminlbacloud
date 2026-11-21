<?php

namespace Modules\Patients\Services;

use App\Models\User;
use Modules\Patients\Models\ClinicalAuditLog;

/**
 * Appends clinical audit entries (Phase 6).
 *
 * Deliberately NOT transactional on its own: it is always called from inside a
 * service's verified tenant transaction, so the audit row commits or rolls back
 * together with the change it describes. Append-only by construction — it only
 * ever inserts.
 */
final class ClinicalAuditRecorder
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function record(
        ?string $patientId,
        string $subjectType,
        ?string $subjectId,
        string $action,
        ?User $actor = null,
        array $changes = [],
    ): ClinicalAuditLog {
        return ClinicalAuditLog::query()->create([
            'patient_id' => $patientId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'actor_id' => $actor?->getKey(),
            'changes' => $changes === [] ? null : $changes,
            'occurred_at' => now(),
        ]);
    }
}
