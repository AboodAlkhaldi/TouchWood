<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Home;

/**
 * A store's orders **as they stand now**, whatever the period (`SalesHomeReads::ordersNow`; dashboard.md §2 E, D2),
 * each counted as the Orders list's tab of the same name counts it - test orders never.
 */
final readonly class OrdersNow
{
    public function __construct(
        /** Placed, not yet approved or rejected: the Waiting for Approval tab. */
        public int $waiting,
        /** Approved, not shipped: the To Ship tab. */
        public int $toShip,
        /** Shipped, not delivered: the Shipped tab. */
        public int $onTheWay,
    ) {}
}
