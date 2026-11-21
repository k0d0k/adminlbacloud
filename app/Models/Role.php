<?php

namespace App\Models;

use App\Platform\Access\ActionScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Role catalog entry (Phase 1B-2A).
 *
 * `key` matches App\Platform\Access\RoleKey. `scope` is platform|tenant|patient|
 * machine and `panel` is the single human panel the role belongs to (null for the
 * machine-only ai_service role, which must never hold human panel permissions).
 *
 * `action_scope` (Requirement 3.a) is the CRUD vs CRU level the role carries:
 * `cru` may view/create/update, `crud` additionally delete. It is derived from
 * the catalog and applied automatically once the role is attached to a user.
 */
#[Fillable(['key', 'name', 'scope', 'panel', 'action_scope', 'is_machine', 'description'])]
class Role extends Model
{
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_machine' => 'boolean',
        ];
    }

    /**
     * The normalised CRUD vs CRU level of this role (fail-closed to CRU).
     */
    public function actionScope(): string
    {
        return ActionScope::normalize($this->action_scope);
    }

    public function allowsDelete(): bool
    {
        return ActionScope::allowsDelete($this->actionScope());
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')->withTimestamps();
    }

    /**
     * @return HasMany<PrincipalRoleAssignment, $this>
     */
    public function principalAssignments(): HasMany
    {
        return $this->hasMany(PrincipalRoleAssignment::class);
    }
}
