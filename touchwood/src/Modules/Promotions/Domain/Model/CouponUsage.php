<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Model;

/** What one customer has done with a coupon (promotions.md §1.1, §1.4): their uses, and whether it was assigned to them. */
final readonly class CouponUsage
{
    public function __construct(
        public int $usesByCustomer,
        public bool $assigned,
        /** The coupon's holds for online payments still open, everyone's and this customer's (§9.5). */
        public int $heldTotal = 0,
        public int $heldByCustomer = 0,
    ) {}
}
