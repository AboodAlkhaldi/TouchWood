<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Model;

/**
 * A placed order as Promotions keeps it (promotions.md §1.5, §1.6, §5): its store and customer, which
 * the segments count, and the ceiling it was placed under, which an edit keeps. Never changed.
 */
final readonly class OrderFact
{
    public function __construct(
        public string $orderId,
        public string $storeId,
        public string $customerId,
        public int $ceilingPercent,
    ) {}
}
