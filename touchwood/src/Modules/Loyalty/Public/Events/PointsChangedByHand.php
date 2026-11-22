<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An admin added or removed a customer's points by hand (loyalty.md §1.9, §6; ops.md §1.5 C13). Ops tells
 * the customer, reading the points, the reason the admin wrote and the balance through
 * `LoyaltyApi::entryForMessage($entryId)`.
 *
 * Ids only, through the outbox, after commit.
 */
final readonly class PointsChangedByHand implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        /** The ADDED or DEDUCTED entry. */
        public int $entryId,
        public string $storeId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
