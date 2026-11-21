<?php

namespace Tests\Feature\Filament;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Subscriptions\Models\SubscriptionPlan;
use App\Platform\Subscriptions\Models\TenantSubscription;
use App\Platform\Tenancy\Models\TenantDatabase;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsAccess;
use Tests\TestCase;

/**
 * Requirement 3.a — the Mitra subscription gate.
 *
 * A Clinic identity whose partner has a subscription ON FILE that is no longer
 * live (period ended, cancelled, expired, pending) is redirected from the
 * workspace to the "hubungi Principal" notice. A partner with a live licence is
 * served normally, and a partner with no subscription at all keeps the existing
 * membership + database contract (the gate is about a LAPSED licence, not about
 * being unlicensed).
 */
class ClinicSubscriptionGateTest extends TestCase
{
    use BuildsAccess, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('clinic'));
    }

    private function clinicUser(): User
    {
        return User::factory()->role(RoleKey::TENANT_OWNER)->create();
    }

    private function registerDatabase(Tenant $tenant, string $status): void
    {
        TenantDatabase::query()->create([
            'tenant_id' => $tenant->getKey(),
            'database_name' => 'tenant_'.$tenant->getKey(),
            'driver' => 'sqlite',
            'status' => $status,
        ]);
    }

    private function plan(): SubscriptionPlan
    {
        return SubscriptionPlan::query()->create([
            'code' => 'plan-'.uniqid(),
            'name' => 'Klinik Plan',
            'status' => SubscriptionPlan::STATUS_ACTIVE,
            'price_amount' => '250000.00',
            'currency' => 'IDR',
            'billing_interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'trial_days' => 0,
            'module_keys' => [],
        ]);
    }

    private function subscribe(Tenant $tenant, array $attributes = []): TenantSubscription
    {
        return TenantSubscription::query()->create(array_merge([
            'tenant_id' => $tenant->getKey(),
            'plan_id' => $this->plan()->getKey(),
            'status' => TenantSubscription::STATUS_ACTIVE,
            'current_period_start' => Carbon::now()->subMonth(),
            'current_period_end' => Carbon::now()->addMonth(),
        ], $attributes));
    }

    public function test_a_partner_with_a_live_subscription_reaches_the_workspace(): void
    {
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->withoutDatabase()->create(['name' => 'Mitra Aktif']);
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);
        $this->registerDatabase($tenant, TenantDatabase::STATUS_MIGRATED);
        $this->subscribe($tenant);

        $this->actingAs($user)->get('/clinic')->assertOk()->assertSee('Mitra Aktif');
    }

    public function test_a_lapsed_period_sends_the_mitra_to_the_contact_principal_notice(): void
    {
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->withoutDatabase()->create(['name' => 'Mitra Lapse']);
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);
        $this->registerDatabase($tenant, TenantDatabase::STATUS_MIGRATED);
        $this->subscribe($tenant, [
            'current_period_start' => Carbon::now()->subMonths(2),
            'current_period_end' => Carbon::now()->subMonth(),
        ]);

        $this->actingAs($user)
            ->get('/clinic')
            ->assertRedirect(route('clinic.subscription.inactive'));
    }

    public function test_a_cancelled_subscription_is_treated_as_lapsed(): void
    {
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->withoutDatabase()->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);
        $this->registerDatabase($tenant, TenantDatabase::STATUS_MIGRATED);
        $this->subscribe($tenant, [
            'status' => TenantSubscription::STATUS_CANCELLED,
            'cancelled_at' => Carbon::now()->subDay(),
        ]);

        $this->actingAs($user)
            ->get('/clinic')
            ->assertRedirect(route('clinic.subscription.inactive'));
    }

    public function test_a_partner_with_no_subscription_keeps_the_existing_contract(): void
    {
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->withoutDatabase()->create(['name' => 'Mitra Baru']);
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);
        $this->registerDatabase($tenant, TenantDatabase::STATUS_MIGRATED);

        $this->actingAs($user)->get('/clinic')->assertOk()->assertSee('Mitra Baru');
    }

    public function test_the_notice_page_renders_and_names_the_partner(): void
    {
        $user = $this->clinicUser();
        $tenant = Tenant::factory()->withoutDatabase()->create(['name' => 'Mitra Lapse']);
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        $this->actingAs($user)
            ->get(route('clinic.subscription.inactive'))
            ->assertOk()
            ->assertSee('Mitra Lapse')
            ->assertSee(__('lba.subscription.inactive_help'));
    }
}
