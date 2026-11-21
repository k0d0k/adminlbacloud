<?php

namespace Modules\Appointments\Exceptions;

use RuntimeException;

/**
 * Thrown when a public booking request cannot be converted into an appointment
 * because the chosen slot is no longer bookable (taken, closed, or otherwise
 * unavailable) at confirmation time.
 *
 * Carries the stable `slot_unavailable` code so the patient portal can map the
 * refusal to a safe, specific message without string-matching.
 */
final class BookingSlotUnavailableException extends RuntimeException
{
    public function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public static function slotUnavailable(): self
    {
        return new self('slot_unavailable', 'The selected slot is no longer available.');
    }

    public static function notConfirmable(string $status): self
    {
        return new self('not_confirmable', "A booking request in [{$status}] cannot be confirmed.");
    }
}
