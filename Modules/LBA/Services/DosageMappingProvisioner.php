<?php

namespace Modules\LBA\Services;

use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Modules\LBA\Dosage\DosageIndicatorMap;
use Modules\LBA\Models\DosageIndicatorMapping;

/**
 * Referensi Rumusan Dosis — provision the reviewed indicator -> Lampiran A
 * mappings into the tenant database (Modules/LBA).
 *
 * Idempotent: re-running never duplicates a mapping (each is first-or-created on
 * its natural key) and never overwrites an existing one, so a locally adjusted
 * mapping is preserved.
 */
final class DosageMappingProvisioner
{
    use RunsVerifiedTenantTransactions;

    /**
     * @return array{created: int, total: int}
     */
    public function provision(?string $referenceKey = null): array
    {
        return $this->tenantTransaction(function () use ($referenceKey): array {
            $created = 0;
            $total = 0;

            foreach (DosageIndicatorMap::mappings() as $mapping) {
                $total++;

                $record = DosageIndicatorMapping::query()->firstOrCreate(
                    [
                        'indicator_key' => $mapping['indicator_key'],
                        'condition_label' => $mapping['condition_label'],
                        'reference_entry_number' => $mapping['reference_entry_number'],
                    ],
                    [
                        'reference_key' => $referenceKey,
                        'review_status' => $mapping['review_status'],
                    ],
                );

                if ($record->wasRecentlyCreated) {
                    $created++;
                }
            }

            return ['created' => $created, 'total' => $total];
        });
    }
}
