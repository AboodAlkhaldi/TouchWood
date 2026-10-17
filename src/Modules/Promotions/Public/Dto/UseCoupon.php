<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Dto;

use Shared\Domain\ValueObject\Money;

/**
 * Placing an order with a coupon (promotions.md §2.1, §2.2): the order's id and public number, the
 * coupon the quote named, the request it was quoted for (the cart's prices, customer, buyer), the
 * discount quoted, whether the order also uses points, and the caller's permission.
 */
final readonly class UseCoupon
{
    public function __construct(
        public string $orderId,
        public string $orderNumber,
        public string $couponId,
        public CouponRequest $request,
        public Money $quotedDiscount,
        public bool $usesPoints,
        public CallerPermission $caller,
    ) {}
}
