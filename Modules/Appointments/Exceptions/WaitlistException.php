<?php

namespace Modules\Appointments\Exceptions;

use RuntimeException;

/**
 * Phase 10 — a fail-closed waitlist error.
 *
 * Carries a stable machine code so the API/portal can map it without parsing
 * the human message.
 */
final class WaitlistException extends RuntimeException
{
    public function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public static function alreadyWaiting(): self
    {
        return new self('already_waiting', __('lba.appointments.errors.already_waiting'));
    }

    public static function unknownBranch(): self
    {
        return new self('unknown_branch', __('lba.appointments.errors.unknown_branch'));
    }

    public static function unknownPatient(): self
    {
        return new self('unknown_patient', __('lba.appointments.errors.unknown_patient'));
    }

    public static function notActive(): self
    {
        return new self('waitlist_entry_inactive', __('lba.appointments.errors.waitlist_entry_inactive'));
    }
}
