<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\PrincipalRoleAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Branches\Models\BranchStaffAssignment;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\ModuleRegistry\ModuleAdministration;
use App\Platform\Subscriptions\Models\SubscriptionPlan;
use App\Platform\Subscriptions\Models\TenantSubscription;
use App\Platform\Subscriptions\SubscriptionLicensing;
use App\Platform\Tenancy\Contracts\ProvisionsTenantDatabases;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use App\Platform\Tenancy\Contracts\RunsTenantMigrations;
use App\Platform\Tenancy\Models\TenantDatabase;
use App\Platform\Tenancy\TenantDatabaseName;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientRegistrar;

/**
 * Synthetic demo data: a sample identity for EVERY role, with its relations.
 *
 * LOCAL / TESTING ONLY — it is invoked by {@see DatabaseSeeder} only when
 * `lba.demo.seed` is enabled (LBA_SEED_DEMO=true) and the environment is local
 * or testing, so a production `migrate --seed` never creates a login with a
 * predictable credential. All identities share the password below.
 *
 * It provisions ONE fully-usable demo partner (mitra) exactly like a real one:
 * a central registry row, its OWN isolated clinical database (provisioned and
 * migrated), a subscription that licenses the clinic modules, and central
 * membership/role rows for a user of every tenant role. It also creates the
 * principal accounts (every platform role) and a patient portal account bound
 * to a patient record in the tenant database.
 *
 * Every step is idempotent: re-running updates existing rows instead of
 * duplicating them.
 */
class DemoDataSeeder extends Seeder
{
    /** The shared password for every demo account. */
    public const PASSWORD = 'password';

    /** Suffix every demo identity's email is built from. */
    public const EMAIL_DOMAIN = 'demo.lbacloud.test';

    /** Slug of the single demo partner. */
    public const TENANT_SLUG = 'klinik-demo';

    /** Plan code of the demo subscription plan. */
    public const PLAN_CODE = 'demo-full';

    /**
     * The module keys the demo plan licenses. Every clinic module, so the demo
     * partner can exercise each module the subscription grants.
     *
     * @var list<string>
     */
    public const DEMO_MODULES = [
        'patients',
        'appointments',
        'lba',
        'consultations',
        'knowledge_base',
        'practitioners',
        'commerce',
    ];

    /**
     * The principal/platform accounts — one per platform role.
     *
     * @var array<string, array{name: string, email: string}>
     */
    private const PRINCIPAL_ACCOUNTS = [
        RoleKey::PRINCIPAL_SUPER_ADMIN => ['name' => 'Demo Super Admin Principal', 'email' => 'principal.superadmin'],
        RoleKey::PRINCIPAL_MODULE_ADMIN => ['name' => 'Demo Module Admin', 'email' => 'principal.moduleadmin'],
        RoleKey::PRINCIPAL_FINANCE => ['name' => 'Demo Finance Principal', 'email' => 'principal.finance'],
        RoleKey::BRAND_MANAGER => ['name' => 'Demo Brand Manager', 'email' => 'brand.manager'],
    ];

    /**
     * The clinic (tenant) accounts — one per tenant role.
     *
     * @var array<string, array{name: string, email: string}>
     */
    private const TENANT_ACCOUNTS = [
        RoleKey::TENANT_OWNER => ['name' => 'Demo Pemilik Mitra', 'email' => 'owner'],
        RoleKey::TENANT_ADMIN => ['name' => 'Demo Admin Mitra', 'email' => 'admin'],
        RoleKey::BRANCH_MANAGER => ['name' => 'Demo Manajer Cabang', 'email' => 'branch.manager'],
        RoleKey::RECEPTIONIST => ['name' => 'Demo Resepsionis', 'email' => 'receptionist'],
        RoleKey::SCHEDULER => ['name' => 'Demo Penjadwal', 'email' => 'scheduler'],
        RoleKey::LOCATION_EVENT_COORDINATOR => ['name' => 'Demo Koordinator Lokasi/Acara', 'email' => 'event.coordinator'],
        RoleKey::LBA_OPERATOR => ['name' => 'Demo Operator LBA', 'email' => 'lba.operator'],
        RoleKey::REVIEWER => ['name' => 'Demo Peninjau', 'email' => 'reviewer'],
        RoleKey::CONSULTANT => ['name' => 'Demo Konsultan', 'email' => 'consultant'],
        RoleKey::COMMERCE_MANAGER => ['name' => 'Demo Manajer Penjualan', 'email' => 'commerce'],
        RoleKey::TENANT_FINANCE => ['name' => 'Demo Keuangan Mitra', 'email' => 'finance'],
    ];

    /** The patient portal account. */
    private const PATIENT_ACCOUNT = ['name' => 'Demo Pasien', 'email' => 'patient'];

    public function run(): void
    {
        // The authorization catalog must exist before any role is attached.
        $this->call(RbacSeeder::class);

        $brand = $this->seedBrand();
        $tenant = $this->seedTenant($brand);
        $this->provisionTenantDatabase($tenant);
        $this->seedSubscription($tenant);

        $this->seedPrincipalAccounts();

        $tenantAccounts = $this->seedTenantAccounts($tenant);
        $patientAccount = $this->seedPatientAccount($tenant);

        $this->seedTenantOperations($tenant, $tenantAccounts, $patientAccount);

        $this->command?->info('Demo data seeded. Every demo account signs in with the password: '.self::PASSWORD);
    }

    /**
     * A single active central brand and the demo partner's primary brand link.
     */
    private function seedBrand(): Brand
    {
        return Brand::query()->updateOrCreate(
            ['slug' => 'demo-brand'],
            [
                'name' => 'LBA Demo Brand',
                'status' => Brand::STATUS_ACTIVE,
                'report_display_name' => 'LBA Demo Brand',
                'report_language' => 'id',
            ],
        );
    }

    /**
     * The central partner registry row, linked to the demo brand as primary.
     */
    private function seedTenant(Brand $brand): Tenant
    {
        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => self::TENANT_SLUG],
            [
                'name' => 'Klinik Demo LBA',
                'status' => Tenant::STATUS_ACTIVE,
                'metadata' => ['demo' => true],
            ],
        );

        $tenant->brands()->syncWithoutDetaching([
            $brand->getKey() => ['is_primary' => true],
        ]);

        return $tenant;
    }

    /**
     * Provision and migrate the partner's OWN isolated clinical database, then
     * register central readiness — exactly the state a real partner reaches via
     * `lba:tenant:provision --migrate`.
     */
    private function provisionTenantDatabase(Tenant $tenant): void
    {
        $tenantId = (string) $tenant->getKey();
        $names = app(TenantDatabaseName::class);

        app(ProvisionsTenantDatabases::class)->provision($tenantId);
        app(RunsTenantMigrations::class)->migrate($tenantId);

        TenantDatabase::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'database_name' => $names->for($tenantId),
                'driver' => $names->driver(),
                'status' => TenantDatabase::STATUS_MIGRATED,
                'last_migrated_at' => now(),
            ],
        );
    }

    /**
     * A plan that licenses every clinic module, and a single active subscription
     * for the demo partner. The subscription installs + entitles the modules.
     */
    private function seedSubscription(Tenant $tenant): TenantSubscription
    {
        // Trust the declared modules so `enable()` / the FeatureGate accept them.
        app(ModuleAdministration::class)->syncCatalog();

        $plan = SubscriptionPlan::query()->updateOrCreate(
            ['code' => self::PLAN_CODE],
            [
                'name' => 'Paket Demo (Semua Modul)',
                'description' => 'Paket demo yang melisensikan seluruh modul klinik untuk mitra contoh.',
                'status' => SubscriptionPlan::STATUS_ACTIVE,
                'price_amount' => 0,
                'currency' => 'IDR',
                'billing_interval' => SubscriptionPlan::INTERVAL_MONTHLY,
                'trial_days' => 0,
                'seat_limit' => 50,
                'sina_credits_per_patient' => 10,
                'module_keys' => self::DEMO_MODULES,
            ],
        );

        $existing = TenantSubscription::query()
            ->where('tenant_id', $tenant->getKey())
            ->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIALING])
            ->first();

        if ($existing instanceof TenantSubscription) {
            return $existing;
        }

        return app(SubscriptionLicensing::class)->subscribe($tenant, $plan, seats: 50);
    }

    /**
     * One active platform account per principal role.
     */
    private function seedPrincipalAccounts(): void
    {
        foreach (self::PRINCIPAL_ACCOUNTS as $roleKey => $definition) {
            $user = $this->seedUser($roleKey, $definition);

            $role = Role::query()->where('key', $roleKey)->sole();

            PrincipalRoleAssignment::query()->updateOrCreate(
                ['user_id' => $user->getKey(), 'role_id' => $role->getKey()],
                [
                    'status' => PrincipalRoleAssignment::STATUS_ACTIVE,
                    'revoked_at' => null,
                ],
            );
        }
    }

    /**
     * One active tenant membership (with its role) per clinic role.
     *
     * @return array<string, User> role key => user
     */
    private function seedTenantAccounts(Tenant $tenant): array
    {
        $accounts = [];

        foreach (self::TENANT_ACCOUNTS as $roleKey => $definition) {
            $accounts[$roleKey] = $this->seedMember($tenant, $roleKey, $definition);
        }

        return $accounts;
    }

    /**
     * The patient portal account, plus its tenant membership (the
     * `patient_customer` role is membership-assignable).
     */
    private function seedPatientAccount(Tenant $tenant): User
    {
        return $this->seedMember($tenant, RoleKey::PATIENT_CUSTOMER, self::PATIENT_ACCOUNT);
    }

    /**
     * Create (or reuse) a central identity with an explicit provisional role and
     * attach it to the tenant through an ACTIVE membership carrying its role.
     *
     * @param  array{name: string, email: string}  $definition
     */
    private function seedMember(Tenant $tenant, string $roleKey, array $definition): User
    {
        $user = $this->seedUser($roleKey, $definition);

        $membership = TenantMembership::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'tenant_id' => $tenant->getKey()],
            [
                'status' => TenantMembership::STATUS_ACTIVE,
                'activated_at' => now(),
                'revoked_at' => null,
            ],
        );

        $role = Role::query()->where('key', $roleKey)->sole();
        $membership->roles()->syncWithoutDetaching([$role->getKey()]);

        return $user;
    }

    /**
     * Create (or reuse) a central identity. `role` is the provisional panel-gate
     * role, so the account can actually reach the panel its role belongs to.
     *
     * @param  array{name: string, email: string}  $definition
     */
    private function seedUser(string $roleKey, array $definition): User
    {
        return User::query()->updateOrCreate(
            ['email' => $definition['email'].'@'.self::EMAIL_DOMAIN],
            [
                'name' => $definition['name'],
                'password' => Hash::make(self::PASSWORD),
                'role' => $roleKey,
                'is_active' => true,
                'locale' => 'id',
                'email_verified_at' => now(),
            ],
        );
    }

    /**
     * Branches, staff assignments and sample patients — all written into the
     * demo partner's OWN isolated database, with the tenant context bound only
     * for the duration of the writes.
     *
     * @param  array<string, User>  $accounts
     */
    private function seedTenantOperations(Tenant $tenant, array $accounts, User $patientAccount): void
    {
        $databases = app(ResolvesTenantDatabase::class);
        $databases->configure((string) $tenant->getKey());

        try {
            $main = $this->seedBranch('PUSAT', 'Cabang Pusat', TenantBranch::LOCATION_PERMANENT);
            $event = $this->seedBranch('CAB-02', 'Cabang Event', TenantBranch::LOCATION_EVENT);

            foreach (self::TENANT_ACCOUNTS as $roleKey => $definition) {
                $branch = $roleKey === RoleKey::LOCATION_EVENT_COORDINATOR ? $event : $main;

                $this->assignStaff($branch, $accounts[$roleKey]);
            }

            $this->seedPatients($tenant, $main, $accounts[RoleKey::TENANT_OWNER], $patientAccount);
        } finally {
            $databases->forget();
        }
    }

    private function seedBranch(string $code, string $name, string $locationType): TenantBranch
    {
        return TenantBranch::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'location_type' => $locationType,
                'status' => TenantBranch::STATUS_ACTIVE,
                'timezone' => config('app.timezone'),
            ],
        );
    }

    private function assignStaff(TenantBranch $branch, User $staff): void
    {
        BranchStaffAssignment::query()->updateOrCreate(
            ['branch_id' => $branch->getKey(), 'user_id' => (string) $staff->getKey()],
            [
                'status' => BranchStaffAssignment::STATUS_ACTIVE,
                'assigned_at' => now(),
                'revoked_at' => null,
            ],
        );
    }

    /**
     * A handful of sample patients (idempotent by email), one of which is bound
     * to the patient portal account so the patient panel has a linked record.
     */
    private function seedPatients(Tenant $tenant, TenantBranch $branch, User $actor, User $patientAccount): void
    {
        $registrar = app(PatientRegistrar::class);

        $samples = [
            ['full_name' => 'Budi Santoso', 'email' => 'budi.santoso@'.self::EMAIL_DOMAIN, 'sex' => Patient::SEX_MALE],
            ['full_name' => 'Siti Aminah', 'email' => 'siti.aminah@'.self::EMAIL_DOMAIN, 'sex' => Patient::SEX_FEMALE],
            ['full_name' => 'Andi Wijaya', 'email' => 'andi.wijaya@'.self::EMAIL_DOMAIN, 'sex' => Patient::SEX_MALE],
        ];

        $linked = null;

        foreach ($samples as $sample) {
            $patient = Patient::query()->where('email', $sample['email'])->first();

            if ($patient === null) {
                $patient = $registrar->create([
                    'full_name' => $sample['full_name'],
                    'email' => $sample['email'],
                    'sex' => $sample['sex'],
                    'registered_branch_id' => $branch->getKey(),
                    'registered_at' => now(),
                    'status' => Patient::STATUS_ACTIVE,
                    'registration_source' => Patient::SOURCE_CLINIC,
                ], $actor);
            }

            $linked ??= $patient;
        }

        // Bind the patient portal account to the first sample record. `user_id`
        // is deliberately not mass-assignable, so it is set explicitly — the
        // same deliberate write an authorized clinic action performs.
        if ($linked instanceof Patient) {
            $linked->forceFill(['user_id' => (string) $patientAccount->getKey()])->save();
        }
    }
}
