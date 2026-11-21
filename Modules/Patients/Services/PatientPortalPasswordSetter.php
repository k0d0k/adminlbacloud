<?php

namespace Modules\Patients\Services;

use App\Models\PatientApiToken;
use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PasswordSetter;
use App\Platform\Access\PermissionKey;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\ModuleScope;
use Modules\Patients\Exceptions\PatientLinkException;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientAuditLog;

/**
 * Clinic-side, authorized reset of a patient's portal account password.
 *
 * `patients.user_id` binds a tenant patient record to a central user account,
 * and that account is what the patient uses to sign in to the portal. This
 * service rotates that account's password on behalf of an authorized clinic
 * identity. Security properties mirror {@see PatientAccountLinker} and
 * {@see PasswordSetter}:
 *
 *  - the actor must hold `patient.profile.manage` for the VERIFIED tenant
 *    (re-checked here, never trusted from the UI);
 *  - only a linked portal account may be reset — an unlinked patient is
 *    refused, so a clinic cannot attach or learn an account through this path;
 *  - the new password is stored through the model's `hashed` cast, never
 *    persisted, logged, returned or emailed in plaintext;
 *  - the target is flagged `must_set_password`, so the administrator-chosen
 *    value is only temporary until the patient signs in and sets their own;
 *  - every credential derived from the previous password (patient REST tokens)
 *    is revoked;
 *  - the change is appended to both the patient audit trail (tenant DB) and the
 *    central redacted audit log.
 */
final class PatientPortalPasswordSetter
{
    public const ACTION = 'portal_password_set';

    public function __construct(
        private readonly ManagesTenantContext $tenants,
        private readonly ChecksPermissions $permissions,
    ) {}

    /**
     * Set a new portal password for the patient's linked account.
     *
     * @throws PatientLinkException when the actor is not authorized, the
     *                              patient is not linked, or the value is empty
     */
    public function set(Patient $patient, string $newPassword, ?User $actor): User
    {
        $this->assertAuthorized($actor);

        if (trim($newPassword) === '') {
            throw PatientLinkException::invalid();
        }

        $userId = $patient->user_id;

        if ($userId === null) {
            throw PatientLinkException::notLinked();
        }

        $account = User::query()->findOrFail($userId);

        $account->forceFill([
            'password' => $newPassword,
            // A value an administrator chooses is temporary: the owner must set
            // their own on next sign-in.
            'must_set_password' => true,
        ])->save();

        $this->revokeDerivedCredentials($account);

        $this->audit($patient, $account, $actor);

        return $account;
    }

    /**
     * Re-derive authorization on the SERVER: the actor must be active and hold
     * `patient.profile.manage` for the VERIFIED tenant. A role held elsewhere
     * can never authorize a reset.
     */
    private function assertAuthorized(?User $actor): void
    {
        if ($actor === null || ! $actor->is_active) {
            throw PatientLinkException::notAuthorized();
        }

        $tenantId = $this->tenants->currentTenantId($actor);

        if ($tenantId === null || ! $this->permissions->allows(
            $actor,
            PermissionKey::PATIENT_PROFILE_MANAGE,
            AccessContext::forTenant($tenantId, null),
        )) {
            throw PatientLinkException::notAuthorized();
        }
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

    private function audit(Patient $patient, User $account, ?User $actor): void
    {
        PatientAuditLog::query()->create([
            'patient_id' => $patient->getKey(),
            'action' => PatientAuditLog::ACTION_PORTAL_PASSWORD_SET,
            'actor_id' => $actor?->getKey(),
            'changes' => null,
            'occurred_at' => now(),
        ]);

        ModuleAuditLog::query()->create([
            'module_key' => 'patients.portal',
            'action' => self::ACTION,
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'scope' => ModuleScope::TENANT,
            'scope_key' => (string) $account->getKey(),
            'actor_id' => $actor?->getKey(),
            'metadata' => [],
        ]);
    }
}
