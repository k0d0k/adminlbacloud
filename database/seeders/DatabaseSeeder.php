<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Production-safe: only the authorization catalog is seeded. No demo users,
     * tenants or memberships are created, so seeding can never introduce an
     * identity with predictable/default credentials.
     *
     * Synthetic demo data (one provider per role plus its relations) is created
     * ONLY when `lba.demo.seed` is enabled (LBA_SEED_DEMO=true) AND the
     * environment is local or staging, so neither a production nor a test
     * `migrate --seed` ever creates a login with a predictable credential.
     */
    public function run(): void
    {
        $this->call(RbacSeeder::class);

        if ($this->shouldSeedDemoData()) {
            $this->call(DemoDataSeeder::class);
        }
    }

    private function shouldSeedDemoData(): bool
    {
        return (bool) config('lba.demo.seed', false)
            && app()->environment(['local', 'staging']);
    }
}
