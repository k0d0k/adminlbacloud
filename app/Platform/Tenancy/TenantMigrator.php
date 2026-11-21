<?php

namespace App\Platform\Tenancy;

use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use App\Platform\Tenancy\Contracts\RunsTenantMigrations;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Applies tenant migrations to a tenant's isolated database (Phase 2A).
 *
 * Delegates to the framework migrator with an explicit `--database`, so only
 * the tenant connection and the tenant migration path are touched and the
 * central schema is never affected.
 */
final class TenantMigrator implements RunsTenantMigrations
{
    public function __construct(private readonly ResolvesTenantDatabase $databases) {}

    public function migrate(string $tenantId): void
    {
        $connection = $this->databases->configure($tenantId);

        $exitCode = Artisan::call('migrate', [
            '--database' => $connection,
            '--path' => (string) config('tenancy.migration_path'),
            '--realpath' => true,
            '--force' => true,
        ]);

        if ($exitCode !== 0) {
            $output = trim(Artisan::output());

            throw new RuntimeException(
                'Tenant migrations exited with status '.$exitCode.'.'
                .($output === '' ? '' : ' '.$this->sanitizeOutput($output)),
            );
        }
    }

    private function sanitizeOutput(string $output): string
    {
        // Console output can contain terminal formatting and unbounded traces.
        $output = preg_replace('/\e\[[\d;]*m/', '', $output) ?? $output;
        $output = preg_replace('/(password\s*[=:]\s*)\S+/i', '$1[redacted]', $output) ?? $output;

        return mb_substr($output, 0, 4000);
    }
}
