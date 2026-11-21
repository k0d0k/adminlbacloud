<?php

namespace Tests\Feature\Platform\Access;

use App\Models\PrincipalRoleAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\ActionScope;
use App\Platform\Access\PrincipalAccessAdministration;
use App\Platform\Access\RbacCatalog;
use App\Platform\Access\RoleKey;
use App\Platform\Access\StaffInvitationOutcome;
use App\Platform\Access\StaffInviter;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccess;
use Tests\TestCase;

/**
 * Requirement 3.a / 3.b / 5 — CRUD vs CRU action scope and the escalation guard.
 *
 * A role carries an ACTION LEVEL, not just a menu: when the role is attached to
 * a user the level applies automatically. A CRU actor must never be able to
 * confer a CRUD (delete-capable) role, so an assignment can never be used to
 * escalate CRU → CRUD.
 */
class RoleActionScopeTest extends TestCase
{
    use BuildsAccess, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_the_action_scope_is_a_pure_function_of_the_role_key(): void
    {
        $scopes = RbacCatalog::roleActionScopes();

        $this->assertSame(collect(RoleKey::ALL)->sort()->values()->all(), collect(array_keys($scopes))->sort()->values()->all());

        foreach (RoleKey::ALL as $roleKey) {
            $expected = in_array($roleKey, [RoleKey::PRINCIPAL_SUPER_ADMIN, RoleKey::TENANT_OWNER, RoleKey::TENANT_ADMIN], true)
                ? ActionScope::CRUD
                : ActionScope::CRU;

            $this->assertSame($expected, $scopes[$roleKey], "role {$roleKey} must be {$expected}");
            $this->assertSame($expected, RbacCatalog::roleActionScope($roleKey));
        }
    }

    public function test_only_crud_roles_allow_a_destructive_action(): void
    {
        $this->assertTrue(ActionScope::allowsDelete(ActionScope::CRUD));
        $this->assertFalse(ActionScope::allowsDelete(ActionScope::CRU));

        $this->assertSame(['view', 'create', 'update'], ActionScope::actions(ActionScope::CRU));
        $this->assertSame(['view', 'create', 'update', 'delete'], ActionScope::actions(ActionScope::CRUD));

        // An unrecognised (or missing) scope never grants delete — fail-closed.
        $this->assertSame(ActionScope::CRU, ActionScope::normalize(null));
        $this->assertSame(ActionScope::CRU, ActionScope::normalize('write'));
        $this->assertFalse(RbacCatalog::roleAllowsDelete(RoleKey::PRINCIPAL_FINANCE));
        $this->assertTrue(RbacCatalog::roleAllowsDelete(RoleKey::PRINCIPAL_SUPER_ADMIN));
    }

    public function test_an_empty_role_set_is_fail_closed_to_cru(): void
    {
        $this->assertSame(ActionScope::CRU, RbacCatalog::highestActionScope([]));
        $this->assertSame(ActionScope::CRU, RbacCatalog::highestActionScope([RoleKey::LBA_OPERATOR, RoleKey::REVIEWER]));
        $this->assertSame(ActionScope::CRUD, RbacCatalog::highestActionScope([RoleKey::REVIEWER, RoleKey::TENANT_ADMIN]));
    }

    public function test_a_cru_actor_cannot_grant_a_crud_role(): void
    {
        $this->assertFalse(RbacCatalog::mayGrantRole([RoleKey::PRINCIPAL_FINANCE], RoleKey::PRINCIPAL_SUPER_ADMIN));
        $this->assertFalse(RbacCatalog::mayGrantRole([RoleKey::BRANCH_MANAGER], RoleKey::TENANT_OWNER));

        // A CRU actor may still grant roles at or below their own level.
        $this->assertTrue(RbacCatalog::mayGrantRole([RoleKey::PRINCIPAL_FINANCE], RoleKey::PRINCIPAL_MODULE_ADMIN));
        $this->assertTrue(RbacCatalog::mayGrantRole([], RoleKey::BRAND_MANAGER));
    }

    public function test_a_crud_actor_may_grant_any_role(): void
    {
        $this->assertTrue(RbacCatalog::mayGrantRole([RoleKey::PRINCIPAL_SUPER_ADMIN], RoleKey::PRINCIPAL_FINANCE));
        $this->assertTrue(RbacCatalog::mayGrantRole([RoleKey::TENANT_OWNER], RoleKey::TENANT_ADMIN));
    }

    public function test_a_null_actor_bypasses_the_guard_for_trusted_contexts(): void
    {
        $this->assertTrue(RbacCatalog::mayGrantRole(null, RoleKey::PRINCIPAL_SUPER_ADMIN));
    }

    public function test_the_seeder_materialises_the_action_scope_onto_roles(): void
    {
        foreach (RbacCatalog::roleActionScopes() as $roleKey => $scope) {
            $role = Role::query()->where('key', $roleKey)->sole();

            $this->assertSame($scope, $role->action_scope);
            $this->assertSame($scope, $role->actionScope());
            $this->assertSame($scope === ActionScope::CRUD, $role->allowsDelete());
        }
    }

    public function test_platform_administration_denies_a_cru_actor_granting_a_crud_role(): void
    {
        $actor = User::factory()->role(RoleKey::PRINCIPAL_FINANCE)->create();
        $this->grantPrincipalRole($actor, RoleKey::PRINCIPAL_FINANCE);

        $target = User::factory()->create();

        $granted = app(PrincipalAccessAdministration::class)
            ->assignRole($target, RoleKey::PRINCIPAL_SUPER_ADMIN, $actor);

        $this->assertFalse($granted);
        $this->assertDatabaseMissing('principal_role_assignments', [
            'user_id' => $target->getKey(),
            'status' => PrincipalRoleAssignment::STATUS_ACTIVE,
        ]);
    }

    public function test_staff_invitation_denies_a_cru_actor_inviting_at_a_crud_level(): void
    {
        $tenant = Tenant::factory()->create();

        $actor = User::factory()->role(RoleKey::RECEPTIONIST)->create();
        $this->giveMembership($actor, $tenant, [RoleKey::RECEPTIONIST]);

        $outcome = app(StaffInviter::class)->invite(
            $tenant,
            'escalation@example.test',
            'Escalation Attempt',
            [RoleKey::TENANT_ADMIN],
            $actor,
        );

        $this->assertSame(StaffInvitationOutcome::STATUS_ESCALATION_FORBIDDEN, $outcome->status);
        $this->assertFalse($outcome->succeeded());
        $this->assertDatabaseMissing('users', ['email' => 'escalation@example.test']);
    }
}
