<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Model;

/** A coupon held while its customer pays online, under the order's future id (promotions.md §9.5). */
final readonly class CouponReservation
{
    public function __construct(
        public string $orderId,
        public string $couponId,
        public string $storeId,
        public string $customerId,
        public int $discountMinor,
    ) {}
}
