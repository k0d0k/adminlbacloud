<?php

namespace Tests\Feature\Database;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Branches\Models\BranchStaffAssignment;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Subscriptions\Models\TenantSubscription;
use App\Platform\Tenancy\Models\TenantDatabase;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Patients\Models\Patient;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * The synthetic demo seeder must provide a usable sample identity for EVERY
 * role — principal, tenant and patient — with the relations each one needs, and
 * it must be safe to run repeatedly.
 */
class DemoDataSeederTest extends TestCase
{
    use BuildsTenantDatabases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootTenantDatabases();
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    public function test_it_creates_a_sample_user_and_relations_for_every_role(): void
    {
        $this->seed(DemoDataSeeder::class);

        $tenant = Tenant::query()->where('slug', DemoDataSeeder::TENANT_SLUG)->sole();

        // A principal account with an ACTIVE assignment exists for every
        // platform role.
        foreach (RoleKey::PRINCIPAL_ROLES as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->sole();

            $this->assertDatabaseHas('principal_role_assignments', [
                'role_id' => $role->getKey(),
                'status' => 'active',
            ]);
        }

        // An ACTIVE membership carrying the role exists for every tenant role,
        // and for the patient portal account.
        foreach (RoleKey::TENANT_ROLES as $roleKey) {
            $this->assertRoleMembership($tenant, $roleKey);
        }

        $this->assertRoleMembership($tenant, RoleKey::PATIENT_CUSTOMER);

        // The partner is fully provisioned and licensed.
        $this->assertDatabaseHas('tenant_databases', [
            'tenant_id' => $tenant->getKey(),
            'status' => TenantDatabase::STATUS_MIGRATED,
        ]);

        $this->assertTrue(TenantSubscription::query()
            ->where('tenant_id', $tenant->getKey())
            ->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIALING])
            ->exists());

        // The tenant-owned relations live in the partner's OWN database.
        $this->tenantDatabases()->configure((string) $tenant->getKey());

        $this->assertSame(2, TenantBranch::query()->count());
        $this->assertSame(count(RoleKey::TENANT_ROLES), BranchStaffAssignment::query()->count());
        $this->assertSame(3, Patient::query()->count());
        $this->assertSame(1, Patient::query()->whereNotNull('user_id')->count());

        $this->tenantDatabases()->forget();
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(DemoDataSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $expectedUsers = count(RoleKey::PRINCIPAL_ROLES) + count(RoleKey::TENANT_ROLES) + 1;

        $this->assertSame($expectedUsers, User::count());
        $this->assertSame(1, Tenant::query()->where('slug', DemoDataSeeder::TENANT_SLUG)->count());
    }

    private function assertRoleMembership(Tenant $tenant, string $roleKey): void
    {
        $role = Role::query()->where('key', $roleKey)->sole();

        $exists = TenantMembership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereHas('roles', fn ($query) => $query->whereKey($role->getKey()))
            ->exists();

        $this->assertTrue($exists, "Missing active membership for role [{$roleKey}].");
    }
}
