<?php

namespace Modules\Patients\Exceptions;

use RuntimeException;

/**
 * A refused clinical-record operation (Phase 3).
 *
 * Every factory returns a safe, user-presentable message; internals are never
 * leaked. Callers translate it into a form error rather than a 500.
 */
final class ClinicalRecordException extends RuntimeException
{
    public static function notEditable(): self
    {
        return new self('A finalised clinical record is frozen. Correct it through an audited amendment, not by editing it.');
    }

    public static function alreadyFinal(): self
    {
        return new self('This clinical encounter has already been finalised.');
    }

    public static function amendmentRequiresReason(): self
    {
        return new self('An amendment must state a reason.');
    }

    public static function amendmentRequiresFinal(): self
    {
        return new self('Only a finalised encounter can be amended; a draft is edited directly.');
    }

    public static function unsupportedGlucoseUnit(string $unit): self
    {
        return new self("Unsupported glucose unit: {$unit}");
    }

    public static function unknownKind(string $kind): self
    {
        return new self("Unknown clinical measurement kind: {$kind}");
    }
}
