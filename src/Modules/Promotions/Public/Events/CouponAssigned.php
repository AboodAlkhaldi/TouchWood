<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A coupon was given to a segment's customers (promotions.md §1.4, §6; ops.md §1.5 C15): **one event for
 * each assignment that added anyone** — none when everyone had it already. Ops pages the customers it
 * added with `PromotionsApi::assignedCustomers($assignmentId, …)` and tells each (the Ops session's choice,
 * 2026-10-28: a segment of thousands is one row in the outbox, not thousands). The account centre shows the
 * coupon at once either way.
 *
 * Ids only, through the outbox, after commit.
 */
final readonly class CouponAssigned implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        /** This assignment's own id, on every row it added. */
        public string $assignmentId,
        public string $couponId,
        public string $storeId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
