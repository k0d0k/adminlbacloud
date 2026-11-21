<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\RoleKey;
use App\Platform\Tenancy\Domain\Contracts\ProbesDomainOwnership;
use App\Platform\Tenancy\Domain\Contracts\ResolvesTenantByDomain;
use App\Platform\Tenancy\Domain\Exceptions\DomainRegistrationException;
use App\Platform\Tenancy\Domain\TenantDomainRegistry;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\TestCase;

/**
 * Phase D — per-partner custom domains.
 *
 * A hostname identifies the partner it is verified for; it NEVER authenticates a
 * user or grants access to data. These tests exercise the single write path
 * (register + DNS-token verification), the fail-closed resolver and the proof
 * that a verified domain cannot replace a sign-in or a membership.
 */
class TenantDomainTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        // No real DNS in tests: the probe returns exactly the records a test
        // publishes, and nothing otherwise (fail closed).
        $this->app->instance(ProbesDomainOwnership::class, new class implements ProbesDomainOwnership
        {
            /** @var list<string> */
            public array $records = [];

            public function txtRecords(string $domain): array
            {
                return $this->records;
            }
        });
    }

    private function registry(): TenantDomainRegistry
    {
        return app(TenantDomainRegistry::class);
    }

    private function probe(): ProbesDomainOwnership
    {
        /** @var ProbesDomainOwnership $probe */
        $probe = app(ProbesDomainOwnership::class);

        return $probe;
    }

    private function resolver(): ResolvesTenantByDomain
    {
        return app(ResolvesTenantByDomain::class);
    }

    public function test_registration_normalises_the_host_and_issues_a_pending_token(): void
    {
        $tenant = Tenant::factory()->create();

        $domain = $this->registry()->register($tenant, 'HTTPS://Klinik.Example.ID:8443/portal?x=1');

        $this->assertSame('klinik.example.id', $domain->domain);
        $this->assertSame(TenantDomain::STATUS_PENDING, $domain->status);
        $this->assertNull($domain->verified_at);
        $this->assertNotEmpty($domain->verification_token);
        $this->assertFalse($domain->isVerified());
    }

    public function test_an_invalid_hostname_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(DomainRegistrationException::class);

        try {
            $this->registry()->register($tenant, 'not a host');
        } catch (DomainRegistrationException $exception) {
            $this->assertSame('invalid_domain', $exception->errorCode());

            throw $exception;
        }
    }

    public function test_the_platform_host_and_reserved_hosts_can_never_be_claimed(): void
    {
        $tenant = Tenant::factory()->create();
        config([
            'app.url' => 'https://platform.lbacloud.com',
            'lba.domains.reserved' => ['reserved.example.id'],
        ]);

        try {
            $this->registry()->register($tenant, 'platform.lbacloud.com');
            $this->fail('The platform host should be reserved.');
        } catch (DomainRegistrationException $exception) {
            $this->assertSame('reserved_domain', $exception->errorCode());
        }

        $this->expectException(DomainRegistrationException::class);

        try {
            $this->registry()->register($tenant, 'reserved.example.id');
        } catch (DomainRegistrationException $exception) {
            $this->assertSame('reserved_domain', $exception->errorCode());

            throw $exception;
        }
    }

    public function test_a_hostname_already_claimed_cannot_be_registered_again(): void
    {
        $first = Tenant::factory()->create();
        $second = Tenant::factory()->create();

        $this->registry()->register($first, 'shared.example.id');

        $this->expectException(DomainRegistrationException::class);

        try {
            $this->registry()->register($second, 'shared.example.id');
        } catch (DomainRegistrationException $exception) {
            $this->assertSame('already_claimed', $exception->errorCode());

            throw $exception;
        }
    }

    public function test_verification_requires_the_published_dns_token(): void
    {
        $tenant = Tenant::factory()->create();
        $domain = $this->registry()->register($tenant, 'clinic.example.id');

        // Wrong token published ⇒ not verified, recorded as failed.
        $this->probe()->records = ['lba-verify-something-else'];
        $this->assertFalse($this->registry()->verify($domain));
        $domain->refresh();
        $this->assertSame(TenantDomain::STATUS_FAILED, $domain->status);
        $this->assertNull($domain->verified_at);

        // The exact token published ⇒ verified.
        $this->probe()->records = ['  '.$domain->verification_token.'  '];
        $this->assertTrue($this->registry()->verify($domain));
        $domain->refresh();
        $this->assertSame(TenantDomain::STATUS_VERIFIED, $domain->status);
        $this->assertNotNull($domain->verified_at);
        $this->assertTrue($domain->isVerified());
    }

    public function test_only_a_verified_domain_on_an_active_partner_resolves(): void
    {
        $active = Tenant::factory()->create();

        // Pending ⇒ no resolution.
        TenantDomain::factory()->forTenant($active)->create(['domain' => 'pending.example.id']);
        $this->assertNull($this->resolver()->resolveHost('pending.example.id'));

        // Verified + active ⇒ resolves to the partner.
        TenantDomain::factory()->forTenant($active)->verified()->create(['domain' => 'active.example.id']);
        $resolved = $this->resolver()->resolveHost('https://active.example.id:443/portal');
        $this->assertInstanceOf(Tenant::class, $resolved);
        $this->assertSame($active->getKey(), $resolved->getKey());
        $this->assertSame(['active.example.id'], $this->resolver()->verifiedDomainsFor((string) $active->getKey()));

        // Verified but SUSPENDED partner ⇒ fail closed.
        $suspended = Tenant::factory()->suspended()->create();
        TenantDomain::factory()->forTenant($suspended)->verified()->create(['domain' => 'suspended.example.id']);
        $this->assertNull($this->resolver()->resolveHost('suspended.example.id'));

        // Verified but SOFT-DELETED partner ⇒ fail closed.
        $deleted = Tenant::factory()->create();
        TenantDomain::factory()->forTenant($deleted)->verified()->create(['domain' => 'deleted.example.id']);
        $deleted->delete();
        $this->assertNull($this->resolver()->resolveHost('deleted.example.id'));

        // Unknown host ⇒ no resolution.
        $this->assertNull($this->resolver()->resolveHost('unknown.example.id'));
        $this->assertNull($this->resolver()->resolveHost(''));
    }

    public function test_a_verified_domain_cannot_replace_a_sign_in_or_a_membership(): void
    {
        $hostTenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();

        TenantDomain::factory()->forTenant($hostTenant)->verified()->create(['domain' => 'host.example.id']);

        // The hostname genuinely identifies $hostTenant...
        $this->assertSame(
            $hostTenant->getKey(),
            $this->resolver()->resolveHost('host.example.id')?->getKey(),
        );

        // ...but it grants NO access: a signed-in user who is a member of ANOTHER
        // partner only never gets $hostTenant's context, whatever host they used.
        $foreignUser = User::factory()->create();
        $this->giveMembership($foreignUser, $otherTenant, [RoleKey::TENANT_ADMIN]);

        $context = app(ManagesTenantContext::class);
        $this->assertSame($otherTenant->getKey(), $context->currentTenantId($foreignUser));
        $this->assertFalse($context->matchesCurrent($foreignUser, (string) $hostTenant->getKey()));

        // A signed-in user with no membership at all has no tenant context,
        // even though the hostname is verified and mapped.
        $memberless = User::factory()->create();
        $this->assertNull($context->currentTenantId($memberless));
        $this->assertFalse($context->hasVerifiedContext($memberless));
    }

    public function test_a_principal_can_register_verify_and_remove_a_domain(): void
    {
        $tenant = Tenant::factory()->create();
        $base = "/principal/master-tenants/{$tenant->id}/relations";

        $this->actingAs($this->principal());

        $this->post("{$base}/domains", ['domain' => 'portal.example.id'])->assertRedirect();

        $domain = TenantDomain::query()->where('domain', 'portal.example.id')->sole();
        $this->assertSame(TenantDomain::STATUS_PENDING, $domain->status);

        // Verify without the published token ⇒ stays non-load-bearing.
        $this->patch("{$base}/domains/{$domain->id}/verify")->assertRedirect();
        $this->assertFalse($domain->refresh()->isVerified());

        // Publish the token, then verify.
        $this->probe()->records = [$domain->verification_token];
        $this->patch("{$base}/domains/{$domain->id}/verify")->assertRedirect();
        $this->assertTrue($domain->refresh()->isVerified());

        $this->delete("{$base}/domains/{$domain->id}")->assertRedirect();
        $this->assertDatabaseMissing('tenant_domains', ['id' => $domain->id]);
    }

    public function test_domain_writes_require_tenant_management(): void
    {
        $tenant = Tenant::factory()->create();
        $base = "/principal/master-tenants/{$tenant->id}/relations";

        $this->get($base)->assertRedirect();

        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE));
        $this->post("{$base}/domains", ['domain' => 'finance.example.id'])->assertForbidden();

        $this->actingAs(User::factory()->role(RoleKey::TENANT_OWNER)->create());
        $this->post("{$base}/domains", ['domain' => 'owner.example.id'])->assertForbidden();
    }

    public function test_a_domain_of_another_partner_is_not_reachable(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $foreign = TenantDomain::factory()->forTenant($other)->create(['domain' => 'foreign.example.id']);

        $this->actingAs($this->principal());

        $this->patch("/principal/master-tenants/{$tenant->id}/relations/domains/{$foreign->id}/verify")
            ->assertNotFound();
        $this->delete("/principal/master-tenants/{$tenant->id}/relations/domains/{$foreign->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('tenant_domains', ['id' => $foreign->id]);
    }
}
