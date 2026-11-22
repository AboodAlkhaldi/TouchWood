<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ListCouponAssignments;

use DateTimeImmutable;

/** The customers an `ASSIGNED` coupon is given to, newest first (promotions.md §1.4, §3). */
final readonly class ListCouponAssignments
{
    public function __construct(
        public string $couponId,
        public ?DateTimeImmutable $beforeAt = null,
        public ?string $beforeCustomerId = null,
        public int $perPage = 50,
    ) {}
}
