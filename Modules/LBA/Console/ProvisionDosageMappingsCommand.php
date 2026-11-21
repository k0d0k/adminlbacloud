<?php

namespace Modules\LBA\Console;

use App\Platform\Tenancy\Console\Concerns\FindsTenant;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Illuminate\Console\Command;
use Modules\LBA\Dosage\DosageIndicatorMap;
use Modules\LBA\Dosage\DosageReferenceCatalog;
use Modules\LBA\Services\DosageMappingProvisioner;

/**
 * Referensi Rumusan Dosis — provision the reviewed indicator -> Lampiran A
 * mappings into ONE tenant database.
 *
 * Idempotent: re-running never duplicates a mapping. Unresolved (ambiguous /
 * combined) indicator labels are provisioned explicitly with `needs_mapping`, so
 * the examiner surface reports "Perlu Pemetaan" instead of guessing.
 */
class ProvisionDosageMappingsCommand extends Command
{
    use FindsTenant;

    protected $signature = 'lba:dosage:provision-mappings
        {tenant : Tenant ULID or slug}
        {--key= : Default reference master key for the mappings}';

    protected $description = 'Provision the reviewed indicator -> Lampiran A dosage mappings for a tenant (idempotent)';

    public function handle(DosageMappingProvisioner $provisioner, ResolvesTenantDatabase $databases): int
    {
        $tenant = $this->findTenant($this, (string) $this->argument('tenant'));

        if ($tenant === null) {
            return self::FAILURE;
        }

        $tenantId = (string) $tenant->getKey();
        $databases->configure($tenantId);

        $result = $provisioner->provision(
            (string) ($this->option('key') ?: DosageReferenceCatalog::DEFAULT_KEY),
        );

        $this->info("Dosage mappings for tenant [{$tenantId}] provisioned.");
        $this->line('  created: '.$result['created']);
        $this->line('  total:   '.$result['total']);
        $this->line('  unresolved labels (Perlu Pemetaan): '.count(DosageIndicatorMap::unresolvedIndicatorKeys()));

        foreach (DosageIndicatorMap::unresolvedIndicatorKeys() as $key) {
            $this->line('    - '.$key);
        }

        return self::SUCCESS;
    }
}
