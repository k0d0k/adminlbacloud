<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Settings\PlatformSettings;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consultations\Models\AiProviderConfig;
use Modules\Consultations\Models\SinaUsageRecord;
use Modules\Consultations\Models\SinaWallet;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\TestCase;

/**
 * Workstream 2 — the Principal "Integrasi SINA AI" TailAdmin surface.
 *
 * Covers the governance screen that owns the SINA provider endpoint per partner,
 * the "price of one chat" (token & price) policy and the read-only usage
 * register — plus the permission boundary that separates viewing (billing OR
 * analytics) from writing (billing only). Credentials are stored encrypted and
 * never serialised; the endpoint guard still refuses a public HTTP host while
 * accepting a Mac Mini on the LAN.
 *
 * All identities and objects here are synthetic (DECISIONS.md PRV-01).
 */
class PrincipalSinaIntegrationTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_the_sina_surface_renders_in_the_shared_shell_and_is_permission_gated(): void
    {
        $admin = $this->principal();

        $response = $this->actingAs($admin)->get(route('principal.tailadmin.sina.index'))->assertOk();
        $response->assertSee('data-principal-account');
        $response->assertSee(__('lba.principal.sina.title'));

        // The active menu entry is marked for the page actually being viewed.
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('principal.tailadmin.sina.index'), '/').'"\s+aria-current="page"/',
            $response->getContent(),
        );

        // An analytics-only principal may VIEW the surface.
        $moduleAdmin = $this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN);
        $this->actingAs($moduleAdmin)->get(route('principal.tailadmin.sina.index'))->assertOk();

        // A tenant owner holds no platform capability at all.
        $owner = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->actingAs($owner)->get(route('principal.tailadmin.sina.index'))->assertForbidden();
    }

    public function test_only_the_billing_capability_may_write_the_sina_surface(): void
    {
        $tenant = Tenant::factory()->create();

        $billing = [
            'enabled' => '1',
            'credits_per_turn' => 1,
            'credits_per_1k_tokens' => 1,
            'currency' => 'sina_credit',
        ];

        $provider = [
            'tenant_id' => $tenant->getKey(),
            'provider' => AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE,
            'base_url' => 'http://mac-mini.local:3000/api',
            'model' => 'qwen2.5:7b',
        ];

        // analytics-only (module admin): view yes, write no.
        $moduleAdmin = $this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN);
        $this->actingAs($moduleAdmin)
            ->patch(route('principal.tailadmin.sina.billing.update'), $billing)->assertForbidden();
        $this->actingAs($moduleAdmin)
            ->post(route('principal.tailadmin.sina.providers.save'), $provider)->assertForbidden();

        // a finance principal holds platform.billing.manage.
        $finance = $this->principal(RoleKey::PRINCIPAL_FINANCE);
        $this->actingAs($finance)
            ->patch(route('principal.tailadmin.sina.billing.update'), $billing)
            ->assertRedirect(route('principal.tailadmin.sina.index'));
    }

    public function test_the_price_of_one_chat_defaults_to_one_credit_and_persists_an_override(): void
    {
        config([
            'lba.sina.billing.enabled' => true,
            'lba.sina.billing.reserve_credits' => 1,
            'lba.sina.billing.credits_per_1k_tokens' => 1,
        ]);

        // The default: one chat costs one credit.
        $this->assertSame(1, app(PlatformSettings::class)->sinaBilling()['credits_per_turn']);

        $this->actingAs($this->principal())
            ->patch(route('principal.tailadmin.sina.billing.update'), [
                'enabled' => '1',
                'credits_per_turn' => 3,
                'credits_per_1k_tokens' => 2,
                'currency' => 'kredit',
            ])->assertRedirect(route('principal.tailadmin.sina.index'));

        $effective = app(PlatformSettings::class)->sinaBilling();

        $this->assertTrue($effective['enabled']);
        $this->assertSame(3, $effective['credits_per_turn']);
        $this->assertSame(2, $effective['credits_per_1k_tokens']);
        $this->assertSame('kredit', $effective['currency']);
    }

    public function test_a_partner_endpoint_is_saved_encrypted_and_a_public_http_host_is_refused(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->principal();

        // A public HTTP host is refused by the endpoint guard.
        $this->actingAs($admin)->post(route('principal.tailadmin.sina.providers.save'), [
            'tenant_id' => $tenant->getKey(),
            'provider' => AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE,
            'base_url' => 'http://insecure.example.com',
            'model' => 'gpt-4o-mini',
        ])->assertSessionHasErrors('base_url');

        $this->assertDatabaseCount('ai_provider_configs', 0);

        // A Mac Mini on the LAN over HTTP is accepted...
        $this->actingAs($admin)->post(route('principal.tailadmin.sina.providers.save'), [
            'tenant_id' => $tenant->getKey(),
            'provider' => AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE,
            'base_url' => 'http://mac-mini.local:3000/api/',
            'model' => 'qwen2.5:7b',
            'api_key' => 'secret-open-webui-key',
            'enabled' => '1',
        ])->assertRedirect(route('principal.tailadmin.sina.index'));

        $config = AiProviderConfig::query()->where('tenant_id', $tenant->getKey())->sole();

        $this->assertSame('http://mac-mini.local:3000/api', $config->base_url);
        $this->assertTrue($config->enabled);
        $this->assertTrue($config->hasCredential());

        // The credential round-trips through the encrypted cast but is never
        // serialised.
        $this->assertSame('secret-open-webui-key', $config->api_key);
        $this->assertArrayNotHasKey('api_key', $config->toArray());
    }

    public function test_the_usage_register_lists_captured_turns(): void
    {
        $tenant = Tenant::factory()->create();

        $wallet = SinaWallet::query()->create([
            'tenant_id' => $tenant->getKey(),
            'patient_id' => 'synthetic-patient',
            'balance_credits' => 9,
            'reserved_credits' => 0,
            'lifetime_consumed_credits' => 1,
            'status' => SinaWallet::STATUS_ACTIVE,
            'currency' => 'sina_credit',
        ]);

        SinaUsageRecord::query()->create([
            'tenant_id' => $tenant->getKey(),
            'wallet_id' => $wallet->getKey(),
            'patient_id' => 'synthetic-patient',
            'provider' => AiProviderConfig::PROVIDER_OPENAI_COMPATIBLE,
            'model_name' => 'qwen2.5:7b',
            'prompt_tokens' => 12,
            'completion_tokens' => 8,
            'credit_units' => 1,
            'idempotency_key' => 'usage-synthetic-1',
            'recorded_at' => now(),
        ]);

        $this->actingAs($this->principal())
            ->get(route('principal.tailadmin.sina.index'))
            ->assertOk()
            ->assertSee('qwen2.5:7b')
            ->assertSee($tenant->name);
    }
}
