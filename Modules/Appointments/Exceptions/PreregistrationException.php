<?php

namespace Modules\Appointments\Exceptions;

use RuntimeException;

/**
 * Phase 10 — a tentative-event preregistration error.
 *
 * A tentative location only accepts preregistration; a confirmed one must go
 * through the normal booking path, so this never confirms anything silently.
 */
final class PreregistrationException extends RuntimeException
{
    public function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public static function unknownBranch(): self
    {
        return new self('unknown_branch', __('lba.appointments.errors.unknown_branch'));
    }

    public static function unknownPatient(): self
    {
        return new self('unknown_patient', __('lba.appointments.errors.unknown_patient'));
    }

    public static function notTentative(): self
    {
        return new self('branch_not_tentative', __('lba.appointments.errors.branch_not_tentative'));
    }
}
