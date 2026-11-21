<?php

namespace Tests\Feature\Platform\Tenancy;

use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use App\Platform\Tenancy\TenantMigrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TenantMigratorTest extends TestCase
{
    public function test_evidence_unique_index_stays_within_the_legacy_innodb_key_limit(): void
    {
        $blueprint = null;
        $migration = require database_path('migrations/tenant/2026_11_09_720006_create_lba_assessment_evidence_links_table.php');

        Schema::shouldReceive('create')
            ->once()
            ->with('lba_assessment_evidence_links', Mockery::on(static function ($callback) use (&$blueprint): bool {
                $connection = DB::connection();
                $connection->getSchemaBuilder();
                $blueprint = new Blueprint($connection, 'lba_assessment_evidence_links');
                $callback($blueprint);

                return true;
            }));

        $migration->up();

        $columns = collect($blueprint->getColumns())
            ->mapWithKeys(static fn ($column): array => [
                $column->name => match ($column->type) {
                    'char', 'string' => $column->length,
                    default => 0,
                },
            ])
            ->all();
        $unique = collect($blueprint->getCommands())->firstWhere('name', 'unique');

        $this->assertNotNull($unique);
        $keyBytes = array_sum(array_map(static fn (string $column): int => $columns[$column] * 4, $unique->columns));
        $this->assertSame(744, $keyBytes);
        $this->assertLessThanOrEqual(767, $keyBytes);
    }

    public function test_dosage_selection_unique_index_name_fits_the_mysql_identifier_limit(): void
    {
        $blueprint = null;
        $migration = require database_path('migrations/tenant/2026_12_13_980005_create_lba_assessment_dosage_selections_table.php');

        Schema::shouldReceive('dropIfExists')
            ->once()
            ->with('lba_assessment_dosage_selections');

        Schema::shouldReceive('create')
            ->once()
            ->with('lba_assessment_dosage_selections', Mockery::on(static function ($callback) use (&$blueprint): bool {
                $connection = DB::connection();
                $connection->getSchemaBuilder();
                $blueprint = new Blueprint($connection, 'lba_assessment_dosage_selections');
                $callback($blueprint);

                return true;
            }));

        $migration->up();

        $unique = collect($blueprint->getCommands())->firstWhere('name', 'unique');

        $this->assertNotNull($unique);
        $this->assertLessThanOrEqual(64, strlen($unique->index));
    }

    public function test_nonzero_migration_exit_code_throws_the_command_output_without_secrets(): void
    {
        $tenantId = '01J00000000000000000000000';
        $databases = Mockery::mock(ResolvesTenantDatabase::class);
        $databases->shouldReceive('configure')->once()->with($tenantId)->andReturn('tenant');

        config()->set('tenancy.migration_path', database_path('migrations/tenant'));

        Artisan::shouldReceive('call')
            ->once()
            ->with('migrate', Mockery::on(fn (array $arguments): bool => $arguments['--database'] === 'tenant'
                && $arguments['--path'] === database_path('migrations/tenant')
                && $arguments['--realpath'] === true
                && $arguments['--force'] === true))
            ->andReturn(1);
        Artisan::shouldReceive('output')
            ->once()
            ->andReturn("\e[31mSQLSTATE[42000]: index too large; password=secret-value\e[0m");

        try {
            (new TenantMigrator($databases))->migrate($tenantId);
            $this->fail('A nonzero migration exit code must fail the tenant setup.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('status 1', $exception->getMessage());
            $this->assertStringContainsString('index too large', $exception->getMessage());
            $this->assertStringContainsString('password=[redacted]', $exception->getMessage());
            $this->assertStringNotContainsString('secret-value', $exception->getMessage());
            $this->assertStringNotContainsString("\e", $exception->getMessage());
        }
    }
}
