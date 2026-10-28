<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A coupon was given to a customer (promotions.md §1.4, §6; ops.md §1.5 C15): one event for each customer
 * a segment's assignment adds — none for one who had it already. Ops tells the customer; the account
 * centre shows it at once either way.
 *
 * Ids only, through the outbox, after commit.
 */
final readonly class CouponAssigned implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $couponId,
        public string $storeId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
