<?php

namespace App\Platform\Site\Services;

use Modules\Appointments\Models\BookingRequest;

/**
 * Result of a public-frontpage visitor booking request (Task 11).
 *
 * Coarse on purpose: the HTTP layer only needs to know whether the request was
 * recorded (and is awaiting email verification) or refused because the chosen
 * slot/branch is not bookable.
 */
final class BookingRequestOutcome
{
    /** The request was recorded (or an interrupted one resumed) as pending. */
    public const STATUS_REQUESTED = 'requested';

    /** The submitted slot is not (or is no longer) bookable. */
    public const STATUS_SLOT_UNAVAILABLE = 'slot_unavailable';

    /** The submitted branch does not exist / is not active in this tenant. */
    public const STATUS_INVALID_BRANCH = 'invalid_branch';

    private function __construct(
        public readonly string $status,
        public readonly ?BookingRequest $request = null,
    ) {}

    public static function requested(BookingRequest $request): self
    {
        return new self(self::STATUS_REQUESTED, $request);
    }

    public static function slotUnavailable(): self
    {
        return new self(self::STATUS_SLOT_UNAVAILABLE);
    }

    public static function invalidBranch(): self
    {
        return new self(self::STATUS_INVALID_BRANCH);
    }

    public function wasRequested(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }
}
