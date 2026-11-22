<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A delivered order earned points (loyalty.md §1.6, §6; ops.md §1.5 C12) — only when it earned more than 0.
 * Ops tells the customer, reading the points, when they expire and the balance through
 * `LoyaltyApi::entryForMessage($entryId)`.
 *
 * Ids only, through the outbox, inside the delivery's transaction, after commit.
 */
final readonly class PointsEarned implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        /** The EARNED entry. */
        public int $entryId,
        public string $orderId,
        public string $storeId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
