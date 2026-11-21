<?php

namespace App\Platform\Tenancy\Domain\Exceptions;

use RuntimeException;

/**
 * Phase D — a rejected custom-domain registration or verification.
 *
 * Every factory carries a stable, non-identifying error code the Principal
 * surface translates into an operator-facing message. No exception ever leaks a
 * token or another partner's identity.
 */
final class DomainRegistrationException extends RuntimeException
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function invalidDomain(string $domain): self
    {
        return new self("The value [{$domain}] is not a usable hostname.", 'invalid_domain');
    }

    public static function reservedDomain(string $domain): self
    {
        return new self("The hostname [{$domain}] is reserved and cannot be claimed by a partner.", 'reserved_domain');
    }

    public static function alreadyClaimed(string $domain): self
    {
        return new self("The hostname [{$domain}] is already claimed.", 'already_claimed');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
