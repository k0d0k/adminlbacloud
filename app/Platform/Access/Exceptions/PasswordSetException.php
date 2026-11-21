<?php

namespace App\Platform\Access\Exceptions;

use RuntimeException;

/**
 * A refused administrative password set.
 *
 * The default message is deliberately uniform and safe to present: whether the
 * target is inactive, or the acting identity lacks the platform access-
 * management capability, the caller is told the same thing. No password
 * material is ever included in the message.
 */
final class PasswordSetException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function notPermitted(): self
    {
        return new self('This password change is not permitted.');
    }

    public static function inactive(): self
    {
        return new self('This password change is not permitted.');
    }

    public static function invalid(): self
    {
        return new self('This password change is not permitted.');
    }
}
