<?php

namespace App\Http\Controllers\Principal;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Access\Exceptions\PasswordResetException;
use App\Platform\Access\Exceptions\PasswordSetException;
use App\Platform\Access\PasswordReset;
use App\Platform\Access\PasswordSetter;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\PrincipalAccessAdministration;
use App\Platform\Access\RbacCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Principal "Users" — administrative credential management across every level.
 *
 * A principal holding the platform access-management capability can, for ANY
 * identity (platform principal, tenant staff of any role, or patient):
 *
 *  - SET a new password directly {@see PasswordSetter} — the value is temporary
 *    and the owner must change it on next sign-in; or
 *  - SEND a single-use reset link to the account's own email
 *    {@see PasswordReset}.
 *
 * Authorization is re-derived on the server for every action (never from the
 * UI), so a principal without the capability is refused even by direct request.
 */
final class UserController extends Controller
{
    use AuthorizesPrincipalResources;

    private const PER_PAGE = 25;

    public function index(Request $request): View
    {
        abort_unless(self::principalCan(PermissionKey::PLATFORM_RBAC_MANAGE), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        $users = User::query()
            ->with(['tenantMemberships.tenant', 'tenantMemberships.roles', 'principalRoleAssignments.role'])
            ->when($search !== '', static function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->appends(array_filter($filters, static fn ($value): bool => $value !== null && $value !== ''));

        return view('principal.users.index', [
            'users' => $users,
            'search' => $search,
            'principalRoles' => RbacCatalog::principalAssignableRoles(),
            'roleActionScopes' => RbacCatalog::roleActionScopes(),
        ]);
    }

    /**
     * Requirement 3.b — add or remove a user's platform (Principal) roles.
     *
     * The submitted checkbox set is the DESIRED state: a checked role is granted
     * and an unchecked one revoked. Every change re-derives authorization
     * server-side and passes through {@see PrincipalAccessAdministration}, so a
     * CRU principal can never escalate an identity to a CRUD role.
     */
    public function updateRoles(Request $request, User $user, PrincipalAccessAdministration $admin): RedirectResponse
    {
        abort_unless(self::principalCan(PermissionKey::PLATFORM_RBAC_MANAGE), 403);

        $data = $request->validate([
            'role_keys' => ['nullable', 'array'],
            'role_keys.*' => ['string', Rule::in(RbacCatalog::principalAssignableRoles())],
        ]);

        $selected = array_values(array_filter((array) ($data['role_keys'] ?? []), 'is_string'));
        $actor = $request->user();

        foreach (RbacCatalog::principalAssignableRoles() as $roleKey) {
            if (in_array($roleKey, $selected, true)) {
                if (! $admin->assignRole($user, $roleKey, $actor)) {
                    return back()->withErrors(['role_keys' => __('lba.settings.grant_denied')]);
                }

                continue;
            }

            $admin->revokeRole($user, $roleKey, $actor);
        }

        return back()->with('success', __('lba.settings.roles_updated', ['name' => (string) $user->name]));
    }

    public function setPassword(Request $request, User $user, PasswordSetter $setter): RedirectResponse
    {
        abort_unless(self::principalCan(PermissionKey::PLATFORM_RBAC_MANAGE), 403);

        $data = $request->validate([
            'password' => ['required', 'string', 'confirmed', Password::min(12)],
        ]);

        $actor = $request->user();

        try {
            $setter->set($user, (string) $data['password'], $actor);
        } catch (PasswordSetException) {
            return back()->withErrors(['password' => __('principal.users.set_failed')]);
        }

        return back()->with('success', __('principal.users.set_done', ['name' => (string) $user->name]));
    }

    public function sendReset(Request $request, User $user, PasswordReset $resets): RedirectResponse
    {
        abort_unless(self::principalCan(PermissionKey::PLATFORM_RBAC_MANAGE), 403);

        try {
            $resets->request($user, $request->user());
        } catch (PasswordResetException) {
            return back()->withErrors(['search' => __('lba.password_reset.super_admin_failed')]);
        }

        return back()->with('success', __('lba.password_reset.super_admin_sent_body', ['email' => (string) $user->email]));
    }
}
