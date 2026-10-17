<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Model;

/**
 * An order's use of a coupon (promotions.md §5): kept for good — a use never comes back — its discount
 * only lowered by a staff edit, the last of which it names.
 */
final readonly class CouponUse
{
    public function __construct(
        public string $orderId,
        public string $couponId,
        public string $storeId,
        public string $customerId,
        public int $discountMinor,
        public ?string $lastEditId,
    ) {}
}
