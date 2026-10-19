<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\StockViews;

/**
 * **What orders hold of a size** (inventory.md §4.3.3, Q20, Q21): "Held by 2 orders: 5 pieces (3 bought,
 * 2 gift)" in its drawer, "2 orders hold 5 of these" under a box before saving. Each order is listed once
 * Sales exists.
 */
final readonly class HoldsView
{
    public function __construct(
        public int $orders,
        public int $pieces,
        public int $bought,
        public int $gift,
    ) {}
}
