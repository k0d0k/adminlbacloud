<?php

namespace App\Platform\Site\Services;

use App\Models\User;

/**
 * Result of a public-frontpage patient self-registration attempt.
 *
 * The outcome is deliberately coarse so the HTTP layer can respond GENERICALLY
 * and never reveal whether an email already has an account (account-enumeration
 * defence). The controller renders the same "check your email" message for a
 * fresh provisioning and for an existing account.
 */
final class PatientRegistrationOutcome
{
    /** A new identity was provisioned (or an interrupted one resumed). */
    public const STATUS_PROVISIONED = 'provisioned';

    /** The email already belongs to an account; the visitor must sign in. */
    public const STATUS_EXISTING_ACCOUNT = 'existing_account';

    /** A membership exists but is not active (revoked/suspended): denied. */
    public const STATUS_MEMBERSHIP_UNAVAILABLE = 'membership_unavailable';

    /** A signed-in identity submitted an email that is not their own. */
    public const STATUS_EMAIL_MISMATCH = 'email_mismatch';

    private function __construct(
        public readonly string $status,
        public readonly ?User $user = null,
        public readonly bool $needsVerification = false,
    ) {}

    public static function provisioned(User $user, bool $needsVerification): self
    {
        return new self(self::STATUS_PROVISIONED, $user, $needsVerification);
    }

    public static function existingAccount(): self
    {
        return new self(self::STATUS_EXISTING_ACCOUNT);
    }

    public static function membershipUnavailable(): self
    {
        return new self(self::STATUS_MEMBERSHIP_UNAVAILABLE);
    }

    public static function emailMismatch(): self
    {
        return new self(self::STATUS_EMAIL_MISMATCH);
    }

    public function wasProvisioned(): bool
    {
        return $this->status === self::STATUS_PROVISIONED;
    }
}
