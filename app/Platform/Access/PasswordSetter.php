<?php

namespace App\Platform\Access;

use App\Models\PatientApiToken;
use App\Models\User;
use App\Platform\Access\Contracts\AuthorizesPrincipal;
use App\Platform\Access\Exceptions\PasswordSetException;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\ModuleScope;

/**
 * Administrative password set — a Super Admin may set a NEW password for any
 * other identity, at every level (platform principal, tenant staff of any role,
 * or patient), from the Principal "Users" surface.
 *
 * Unlike {@see PasswordReset} (which only emails a one-time link so the actor
 * never learns the password), this flow deliberately chooses the value — so it
 * is constrained:
 *
 *  - the acting identity is re-checked HERE (active + platform access-
 *    management capability), never trusted from the UI;
 *  - an identity may never set its OWN password through this path — self-
 *    service must prove the current password via {@see PasswordChanger};
 *  - the chosen password is validated by the caller, stored through the model's
 *    `hashed` cast (never plaintext) and the target is flagged
 *    `must_set_password`, so the value is only a temporary credential until its
 *    owner signs in and sets their own;
 *  - every credential derived from the previous password (patient REST tokens)
 *    is revoked, and the change is appended to the central, redacted audit
 *    trail;
 *  - the plaintext is never persisted, logged, returned or emailed.
 */
final class PasswordSetter
{
    public const ACTION = 'password_set_by_admin';

    public function __construct(private readonly AuthorizesPrincipal $principals) {}

    /**
     * Set a new password for $target on behalf of $actor.
     *
     * @throws PasswordSetException when the actor may not, or the target is
     *                              inactive, or the value is empty
     */
    public function set(User $target, string $newPassword, User $actor): User
    {
        if (! $this->maySetFor($actor, $target)) {
            $this->deny($target, $actor, 'not_permitted', PasswordSetException::notPermitted());
        }

        if (! $target->is_active) {
            $this->deny($target, $actor, 'inactive', PasswordSetException::inactive());
        }

        if (trim($newPassword) === '') {
            $this->deny($target, $actor, 'empty', PasswordSetException::invalid());
        }

        $target->forceFill([
            'password' => $newPassword,
            // A value an administrator chooses is temporary: the owner must set
            // their own on next sign-in.
            'must_set_password' => true,
        ])->save();

        $this->revokeDerivedCredentials($target);

        $this->audit($target, $actor, ModuleAuditLog::RESULT_ALLOWED);

        return $target->refresh();
    }

    /**
     * Whether $actor may set $target's password: an active principal holding the
     * access-management capability, acting on a DIFFERENT identity. Self-service
     * is a separate, current-password-checked flow.
     */
    public function maySetFor(User $actor, User $target): bool
    {
        if ((string) $actor->getKey() === (string) $target->getKey()) {
            return false;
        }

        return $actor->is_active
            && $this->principals->can($actor, PermissionKey::PLATFORM_RBAC_MANAGE);
    }

    /**
     * Revoke credential material derived from the previous password: patient
     * REST API tokens become unusable immediately. Web sessions are handled by
     * `AuthenticateSession` (the stored password hash no longer matches).
     */
    private function revokeDerivedCredentials(User $user): void
    {
        PatientApiToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function deny(User $target, User $actor, string $reason, PasswordSetException $exception): never
    {
        $this->audit($target, $actor, ModuleAuditLog::RESULT_DENIED, $reason);

        throw $exception;
    }

    private function audit(User $target, ?User $actor, string $result, ?string $reason = null): void
    {
        ModuleAuditLog::query()->create([
            'module_key' => 'platform.rbac',
            'action' => self::ACTION,
            'result' => $result,
            'reason' => $reason,
            'scope' => ModuleScope::PLATFORM,
            'scope_key' => (string) $target->getKey(),
            'actor_id' => $actor?->getKey(),
            'metadata' => [],
        ]);
    }
}
