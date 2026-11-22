<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ViewCoupon;

/** One coupon in full, for its page and its editor (promotions.md §3). */
final readonly class ViewCoupon
{
    public function __construct(
        public string $couponId,
    ) {}
}
