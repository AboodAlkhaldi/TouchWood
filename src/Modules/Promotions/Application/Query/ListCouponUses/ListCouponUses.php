<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ListCouponUses;

use DateTimeImmutable;

/** A coupon's uses, newest first (promotions.md §3). */
final readonly class ListCouponUses
{
    public function __construct(
        public string $couponId,
        public ?DateTimeImmutable $beforeAt = null,
        public ?int $beforeId = null,
        public int $perPage = 50,
    ) {}
}
