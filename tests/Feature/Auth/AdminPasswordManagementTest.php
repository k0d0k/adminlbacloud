<?php

namespace Tests\Feature\Auth;

use App\Models\PatientApiToken;
use App\Models\PrincipalRoleAssignment;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Exceptions\PasswordSetException;
use App\Platform\Access\PasswordReset;
use App\Platform\Access\PasswordSetter;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\TestCase;

/**
 * Administrative password set — a Super Admin / Principal holding the platform
 * access-management capability may set a NEW password for ANY other identity,
 * at every level (platform principal, tenant staff of any role, or patient).
 *
 * The chosen value is deliberately learned by the actor, so this flow is
 * constrained: an identity can never set its OWN password through it (self-
 * service must prove the current password), the value is stored hashed and
 * flagged temporary (`must_set_password`), every derived credential is revoked,
 * and every outcome — allowed or denied — is audited without ever recording the
 * plaintext.
 */
class AdminPasswordManagementTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, RefreshDatabase;

    private const NEW_PASSWORD = 'a-strong-temporary-pass-2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('principal'));
    }

    /*
    |--------------------------------------------------------------------------
    | 1. The capability — a principal sets a password for ANY other identity
    |--------------------------------------------------------------------------
    */

    public function test_a_principal_can_set_a_new_password_for_another_identity(): void
    {
        $actor = $this->principal();
        $target = User::factory()->create();

        app(PasswordSetter::class)->set($target, self::NEW_PASSWORD, $actor);

        $target->refresh();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $target->password));
        $this->assertFalse(Hash::check('password', $target->password));
        // A value an administrator chooses is only a TEMPORARY credential.
        $this->assertTrue($target->must_set_password);

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => 'platform.rbac',
            'action' => PasswordSetter::ACTION,
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'scope' => 'platform',
            'scope_key' => (string) $target->getKey(),
            'actor_id' => $actor->getKey(),
        ]);
    }

    public function test_a_principal_can_set_a_password_for_every_level_of_identity(): void
    {
        $actor = $this->principal();

        $targets = [
            RoleKey::PRINCIPAL_SUPER_ADMIN => User::factory()->role(RoleKey::PRINCIPAL_SUPER_ADMIN)->create(),
            RoleKey::TENANT_ADMIN => User::factory()->role(RoleKey::TENANT_ADMIN)->create(),
            RoleKey::LBA_OPERATOR => User::factory()->role(RoleKey::LBA_OPERATOR)->create(),
            RoleKey::PATIENT_CUSTOMER => User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create(),
        ];

        foreach ($targets as $roleKey => $target) {
            app(PasswordSetter::class)->set($target, self::NEW_PASSWORD, $actor);

            $this->assertTrue(
                Hash::check(self::NEW_PASSWORD, $target->refresh()->password),
                "A principal must be able to set a password for a [{$roleKey}] identity.",
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Fail-closed negatives — every refusal is audited
    |--------------------------------------------------------------------------
    */

    public function test_an_identity_may_not_set_its_own_password_through_this_path(): void
    {
        $actor = $this->principal();
        $original = $actor->password;

        try {
            app(PasswordSetter::class)->set($actor, self::NEW_PASSWORD, $actor);
            $this->fail('Self-service must use the current-password flow, never this one.');
        } catch (PasswordSetException) {
            // expected — fails closed
        }

        $this->assertSame($original, $actor->refresh()->password);

        $this->assertDatabaseHas('module_audit_logs', [
            'action' => PasswordSetter::ACTION,
            'result' => ModuleAuditLog::RESULT_DENIED,
            'reason' => 'not_permitted',
            'scope_key' => (string) $actor->getKey(),
        ]);
    }

    public function test_an_identity_without_the_access_management_capability_cannot_set_a_password(): void
    {
        $actor = User::factory()->role(RoleKey::TENANT_ADMIN)->create();
        $target = User::factory()->create();

        try {
            app(PasswordSetter::class)->set($target, self::NEW_PASSWORD, $actor);
            $this->fail('A tenant identity must not set another identity\'s password.');
        } catch (PasswordSetException) {
            // expected
        }

        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $target->refresh()->password));
        $this->assertDatabaseHas('module_audit_logs', [
            'action' => PasswordSetter::ACTION,
            'result' => ModuleAuditLog::RESULT_DENIED,
            'reason' => 'not_permitted',
        ]);
    }

    public function test_a_principal_without_the_access_management_capability_is_also_denied(): void
    {
        // A finance principal holds platform permissions, but NOT rbac.manage.
        $actor = $this->principal(RoleKey::PRINCIPAL_FINANCE);
        $target = User::factory()->create();

        $this->assertFalse(app(PasswordSetter::class)->maySetFor($actor, $target));

        $this->expectException(PasswordSetException::class);

        app(PasswordSetter::class)->set($target, self::NEW_PASSWORD, $actor);
    }

    public function test_a_suspended_actor_cannot_set_a_password_for_another_identity(): void
    {
        $actor = $this->principal();
        $actor->forceFill(['is_active' => false])->save();

        $target = User::factory()->create();

        $this->assertFalse(app(PasswordSetter::class)->maySetFor($actor->refresh(), $target));

        $this->expectException(PasswordSetException::class);

        app(PasswordSetter::class)->set($target, self::NEW_PASSWORD, $actor->refresh());
    }

    public function test_a_suspended_target_is_refused_and_audited(): void
    {
        $actor = $this->principal();
        $target = User::factory()->suspended()->create();

        try {
            app(PasswordSetter::class)->set($target, self::NEW_PASSWORD, $actor);
            $this->fail('A suspended identity must not receive a new credential.');
        } catch (PasswordSetException) {
            // expected
        }

        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $target->refresh()->password));
        $this->assertDatabaseHas('module_audit_logs', [
            'action' => PasswordSetter::ACTION,
            'result' => ModuleAuditLog::RESULT_DENIED,
            'reason' => 'inactive',
        ]);
    }

    public function test_an_empty_password_is_refused(): void
    {
        $actor = $this->principal();
        $target = User::factory()->create();
        $original = $target->password;

        try {
            app(PasswordSetter::class)->set($target, '   ', $actor);
            $this->fail('An empty password must never be stored.');
        } catch (PasswordSetException) {
            // expected
        }

        $this->assertSame($original, $target->refresh()->password);
        $this->assertDatabaseHas('module_audit_logs', [
            'action' => PasswordSetter::ACTION,
            'result' => ModuleAuditLog::RESULT_DENIED,
            'reason' => 'empty',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. The plaintext never leaks and derived credentials are revoked
    |--------------------------------------------------------------------------
    */

    public function test_the_chosen_password_is_never_stored_as_plaintext(): void
    {
        $actor = $this->principal();
        $target = User::factory()->create();

        app(PasswordSetter::class)->set($target, self::NEW_PASSWORD, $actor);

        $stored = (string) $target->refresh()->password;

        $this->assertNotSame(self::NEW_PASSWORD, $stored);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, $stored);
    }

    public function test_setting_a_password_revokes_derived_patient_api_tokens(): void
    {
        $actor = $this->principal();
        $target = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();

        $token = PatientApiToken::query()->create([
            'user_id' => $target->getKey(),
            'tenant_id' => Tenant::factory()->create()->getKey(),
            'name' => 'synthetic',
            'token' => hash('sha256', 'raw-secret'),
            'abilities' => ['report.read.published'],
        ]);

        $this->assertTrue($token->isUsable());

        app(PasswordSetter::class)->set($target, self::NEW_PASSWORD, $actor);

        $this->assertNotNull($token->refresh()->revoked_at);
        $this->assertFalse($token->isUsable());
    }

    public function test_audit_records_never_carry_the_password(): void
    {
        $actor = $this->principal();
        $target = User::factory()->create();

        app(PasswordSetter::class)->set($target, self::NEW_PASSWORD, $actor);

        foreach (ModuleAuditLog::query()->get() as $log) {
            $this->assertStringNotContainsString(self::NEW_PASSWORD, json_encode($log->toArray()));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 4. The Principal "Users" surface — HTTP round-trip and authorization
    |--------------------------------------------------------------------------
    */

    public function test_the_users_page_renders_for_a_principal(): void
    {
        $principal = $this->principal();
        User::factory()->create(['name' => 'Synthetic Target']);

        $this->actingAs($principal)
            ->get('/principal/users')
            ->assertOk()
            ->assertSee(__('principal.users.title'))
            ->assertSee('Synthetic Target');
    }

    public function test_the_users_page_distinguishes_account_and_membership_status(): void
    {
        $principal = $this->principal();
        $user = User::factory()->create(['name' => 'Dr Babul']);
        $tenant = Tenant::factory()->create(['name' => 'Babul Syiffa Indonesia']);
        $this->giveMembership($user, $tenant, [], TenantMembership::STATUS_INVITED);

        $this->actingAs($principal)
            ->get('/principal/users')
            ->assertOk()
            ->assertSee('Babul Syiffa Indonesia')
            ->assertSee(__('lba.tenants.member_status_invited'))
            ->assertSee(__('principal.users.tenant_active'))
            ->assertSee(__('principal.users.membership_hint'));
    }

    public function test_the_users_page_is_forbidden_for_an_identity_without_the_capability(): void
    {
        $actor = User::factory()->role(RoleKey::TENANT_ADMIN)->create();

        $this->actingAs($actor)
            ->get('/principal/users')
            ->assertForbidden();
    }

    public function test_the_users_page_offers_a_platform_role_assignment_form(): void
    {
        $principal = $this->principal();
        $target = User::factory()->create(['name' => 'Synthetic Target']);

        $this->actingAs($principal)
            ->get('/principal/users')
            ->assertOk()
            ->assertSee(__('principal.users.roles_action'))
            ->assertSee(route('principal.tailadmin.users.roles', $target))
            ->assertSee(__('lba.roles.'.RoleKey::PRINCIPAL_SUPER_ADMIN));
    }

    public function test_a_principal_can_grant_and_revoke_a_platform_role_through_the_endpoint(): void
    {
        $principal = $this->principal();
        $target = User::factory()->create();

        $this->actingAs($principal)
            ->patch('/principal/users/'.$target->getKey().'/roles', [
                'role_keys' => [RoleKey::PRINCIPAL_FINANCE],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('principal_role_assignments', [
            'user_id' => $target->getKey(),
            'status' => PrincipalRoleAssignment::STATUS_ACTIVE,
        ]);

        // The submitted set is the DESIRED state — an empty set revokes.
        $this->actingAs($principal)
            ->patch('/principal/users/'.$target->getKey().'/roles', ['role_keys' => []])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('principal_role_assignments', [
            'user_id' => $target->getKey(),
            'status' => PrincipalRoleAssignment::STATUS_ACTIVE,
        ]);
    }

    public function test_a_principal_can_set_a_password_through_the_endpoint(): void
    {
        $principal = $this->principal();
        $target = User::factory()->create();

        $this->actingAs($principal)
            ->patch('/principal/users/'.$target->getKey().'/password', [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $target->refresh();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $target->password));
        $this->assertTrue($target->must_set_password);
    }

    public function test_the_endpoint_rejects_a_weak_password(): void
    {
        $principal = $this->principal();
        $target = User::factory()->create();

        $this->actingAs($principal)
            ->patch('/principal/users/'.$target->getKey().'/password', [
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $target->refresh()->password));
    }

    public function test_the_endpoint_is_forbidden_for_a_non_principal(): void
    {
        $actor = User::factory()->role(RoleKey::TENANT_ADMIN)->create();
        $target = User::factory()->create();

        $this->actingAs($actor)
            ->patch('/principal/users/'.$target->getKey().'/password', [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertForbidden();

        $this->assertTrue(Hash::check('password', $target->refresh()->password));
    }

    public function test_a_principal_can_send_a_reset_link_through_the_endpoint(): void
    {
        Notification::fake();

        $principal = $this->principal();
        $target = User::factory()->create();

        $this->actingAs($principal)
            ->post('/principal/users/'.$target->getKey().'/password-reset')
            ->assertRedirect()
            ->assertSessionHas('success');

        Notification::assertSentTo($target, ResetPassword::class);

        $this->assertDatabaseHas('module_audit_logs', [
            'action' => PasswordReset::ACTION_REQUESTED,
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'scope_key' => (string) $target->getKey(),
            'actor_id' => $principal->getKey(),
        ]);
    }
}
