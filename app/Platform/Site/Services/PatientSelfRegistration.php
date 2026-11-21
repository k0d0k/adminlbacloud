<?php

namespace App\Platform\Site\Services;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\VerifyPatientEmail;
use App\Platform\Access\RoleKey;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientAccountLinker;
use Modules\Patients\Services\PatientRegistrar;

/**
 * Website CMS — public-frontpage patient self-registration (Task 8).
 *
 * A visitor of a clinic frontpage can create a patient account. Provisioning
 * touches TWO databases that share no transaction:
 *
 *  - CENTRAL: the `User` identity and its `TenantMembership` for the clinic;
 *  - TENANT:  one `Patient` row owned by the clinic's isolated database.
 *
 * There is deliberately NO `DB::transaction` spanning the two connections —
 * that is impossible and would be a false guarantee. Instead every step is
 * IDEMPOTENT and ordered so an interrupted provisioning can always be resumed
 * without creating duplicates:
 *
 *  1. resolve / create the central identity (never a duplicate; never reveal
 *     whether the email exists);
 *  2. ensure an ACTIVE membership (a revoked/suspended membership is never
 *     resurrected);
 *  3. ensure exactly one `Patient` row bound to this account in the tenant DB.
 *
 * The patient row is NEVER matched to an existing medical record by name, email
 * or phone: a self-registered patient always gets a NEW row. `user_id` is set
 * by an explicit `forceFill` here — the column stays non-mass-assignable, and
 * only this self-registration path (a brand-new row) ever writes it outside the
 * proof-based {@see PatientAccountLinker}.
 *
 * The portal stays CLOSED until the email is verified AND provisioning has
 * completed (see {@see enforceProvisioned()} / the portal guard).
 */
final class PatientSelfRegistration
{
    use RunsVerifiedTenantTransactions;

    /** How long the email verification link stays valid. */
    public const VERIFICATION_TTL_HOURS = 24;

    public function __construct(
        private readonly ResolvesTenantDatabase $databases,
        private readonly PatientRegistrar $patients,
    ) {}

    /**
     * Register (or resume registering) a patient for one clinic.
     *
     * @param  User|null  $actor  the signed-in identity when the action is
     *                            explicit (a logged-in visitor registering for
     *                            another clinic); null for a guest
     */
    public function register(
        Tenant $tenant,
        string $name,
        string $email,
        ?string $phone,
        string $password,
        ?User $actor = null,
    ): PatientRegistrationOutcome {
        $email = Str::lower(trim($email));
        $name = trim($name);

        // A signed-in identity may only register ITS OWN email; a hostile form
        // can never attach a membership or patient row to a different account.
        if ($actor !== null && Str::lower((string) $actor->email) !== $email) {
            return PatientRegistrationOutcome::emailMismatch();
        }

        $user = $actor;
        $needsVerification = false;

        if ($user === null) {
            $existing = User::query()->whereRaw('lower(email) = ?', [$email])->first();

            if ($existing instanceof User) {
                // Resume ONLY an interrupted SELF-registration for the same
                // inbox (a patient account still pending verification). Any
                // other existing identity is answered generically — the visitor
                // is sent to sign in and the response never reveals whether the
                // account exists.
                if ($this->isResumableSelfRegistration($existing)) {
                    $user = $existing;
                } else {
                    return PatientRegistrationOutcome::existingAccount();
                }
            } else {
                $user = User::query()->create([
                    'name' => $name !== '' ? $name : $email,
                    'email' => $email,
                    'phone' => $phone,
                    'password' => $password,
                    'role' => RoleKey::PATIENT_CUSTOMER,
                    'is_active' => true,
                ]);
            }
        }

        // ---- Central: membership (idempotent) ------------------------------
        $membership = TenantMembership::query()->firstOrCreate(
            ['user_id' => $user->getKey(), 'tenant_id' => $tenant->getKey()],
            ['status' => TenantMembership::STATUS_ACTIVE],
        );

        // A pre-existing membership that is NOT active was revoked/suspended or
        // never activated: never silently resurrect it.
        if ($membership->status !== TenantMembership::STATUS_ACTIVE) {
            return PatientRegistrationOutcome::membershipUnavailable();
        }

        $patientRoleId = Role::query()->where('key', RoleKey::PATIENT_CUSTOMER)->value('id');
        if ($patientRoleId !== null) {
            $membership->roles()->syncWithoutDetaching([$patientRoleId]);
        }

        // ---- Tenant: the clinic patient row (idempotent) -------------------
        $this->provisionPatient($tenant, $user, $name, $email, $phone);

        // ---- Email verification ------------------------------------------
        $needsVerification = $user->email_verified_at === null;

        if ($needsVerification) {
            $this->sendVerification($user, $tenant);
        }

        return PatientRegistrationOutcome::provisioned($user, $needsVerification);
    }

    /**
     * Ensure provisioning is complete for a verified identity, WITHOUT sending
     * any email. Used by the verification endpoint so the portal only opens once
     * the tenant patient row exists — an interrupted registration is repaired
     * here, and a repeated call is a no-op.
     */
    public function enforceProvisioned(Tenant $tenant, User $user): void
    {
        $membership = TenantMembership::query()->firstOrCreate(
            ['user_id' => $user->getKey(), 'tenant_id' => $tenant->getKey()],
            ['status' => TenantMembership::STATUS_ACTIVE],
        );

        if ($membership->status !== TenantMembership::STATUS_ACTIVE) {
            return;
        }

        $this->provisionPatient($tenant, $user, (string) $user->name, (string) $user->email, $user->phone);
    }

    /**
     * True only for an account this platform itself created for a pending
     * self-registration: a patient identity whose email is not yet verified.
     */
    private function isResumableSelfRegistration(User $user): bool
    {
        return $user->email_verified_at === null
            && $user->role === RoleKey::PATIENT_CUSTOMER;
    }

    /**
     * Create the tenant patient row bound to this account, or reuse the one
     * already bound. Never matches by name, email or phone.
     */
    private function provisionPatient(Tenant $tenant, User $user, string $name, string $email, ?string $phone): void
    {
        $this->databases->configure((string) $tenant->getKey());

        try {
            $this->tenantTransaction(function () use ($user, $name, $email, $phone): void {
                $bound = Patient::query()->where('user_id', (string) $user->getKey())->first();

                if ($bound !== null) {
                    return;
                }

                $patient = $this->patients->create([
                    'full_name' => $name !== '' ? $name : (string) $user->name,
                    'email' => $email,
                    'phone' => $phone,
                    'status' => Patient::STATUS_ACTIVE,
                    'registration_source' => Patient::SOURCE_SELF,
                    'registered_at' => now(),
                    'self_registered_at' => now(),
                ], $user);

                // Explicit, non-mass-assignable binding of the NEW row to the
                // account that just registered it.
                $patient->forceFill(['user_id' => (string) $user->getKey()])->save();
            });
        } finally {
            $this->databases->forget();
        }
    }

    private function sendVerification(User $user, Tenant $tenant): void
    {
        $url = URL::temporarySignedRoute('patient.register.verify', now()->addHours(self::VERIFICATION_TTL_HOURS), [
            'user' => (string) $user->getKey(),
            'tenant' => (string) $tenant->slug,
        ]);

        Notification::route('mail', (string) $user->email)->notify(
            new VerifyPatientEmail($user, (string) $tenant->name, $url),
        );
    }
}
