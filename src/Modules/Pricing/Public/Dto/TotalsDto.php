<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Dto;

use Shared\Domain\ValueObject\Money;

/**
 * The canonical amounts of an order (handoff §10.2; pricing.md §1.6, §1.7):
 *
 *   goods_total  = net_subtotal - coupon_discount - points_discount
 *   taxable_base = goods_total + shipping
 *   vat          = taxable_base x the store's rate, rounded once, half up
 *   order_total  = taxable_base + vat
 *
 * Every threshold in the system binds to one of these names, never to a number of its own.
 */
final readonly class TotalsDto
{
    public function __construct(
        public Money $grossSubtotal,
        public Money $netSubtotal,
        public Money $couponDiscount,
        public Money $pointsDiscount,
        public Money $goodsTotal,
        public Money $shipping,
        public Money $taxableBase,
        public Money $vat,
        public Money $orderTotal,
        public int $taxRateBasisPoints,
    ) {}
}
