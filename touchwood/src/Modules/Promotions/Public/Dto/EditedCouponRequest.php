<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Dto;

use Modules\Pricing\Public\Dto\CartPricesDto;
use Modules\Promotions\Public\Enums\Audience;
use Shared\Domain\ValueObject\Money;

/**
 * An order's coupon after a staff edit (promotions.md §1.3, the exception; §2.2): the order, its coupon,
 * the edited order's prices (Pricing's `pricesForEdit`), who is buying, **the whole room under the
 * order's own ceiling** (`orderDiscountRoom` for the edited prices) — the coupon is trimmed to it only
 * when it alone passes it — and the coupon's discount now, the most it may be.
 */
final readonly class EditedCouponRequest
{
    public function __construct(
        public string $orderId,
        public string $couponId,
        public CartPricesDto $prices,
        public Audience $audience,
        public Money $room,
        public Money $discountNow,
    ) {}
}
