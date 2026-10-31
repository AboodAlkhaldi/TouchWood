<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Dto;

use Shared\Domain\ValueObject\Money;

/**
 * Holding the quoted coupon while the customer pays online (promotions.md §9.5): the order's future id,
 * the coupon, the request it was quoted for, the discount quoted, whether the order also uses points,
 * and the caller's permission - as `UseCoupon`, with no order number yet.
 */
final readonly class ReserveCoupon
{
    public function __construct(
        public string $orderId,
        public string $couponId,
        public CouponRequest $request,
        public Money $quotedDiscount,
        public bool $usesPoints,
        public CallerPermission $caller,
    ) {}
}
