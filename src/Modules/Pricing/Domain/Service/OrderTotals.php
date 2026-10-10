<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Service;

use Brick\Math\RoundingMode;
use Modules\Pricing\Domain\Exception\AmountsInvalid;
use Modules\Pricing\Domain\Exception\LinesWithoutPrice;
use Modules\Pricing\Public\Dto\CartPricesDto;
use Modules\Pricing\Public\Dto\TotalsDto;
use Shared\Domain\ValueObject\Money;

/**
 * Every amount of an order, in one place (pricing.md §1.7; handoff §10.2) - a pure function: no
 * database, no clock; the same input gives the same answer.
 *
 *   goods_total  = net_subtotal − coupon_discount − points_discount
 *   taxable_base = goods_total + shipping
 *   vat          = taxable_base × the rate     rounded once, half up, to the coin
 *   order_total  = taxable_base + vat
 *
 * The rate is the one the prices were read with (`CartPricesDto::taxRateBasisPoints`) - an edited
 * order's own (§1.11).
 */
final class OrderTotals
{
    public static function of(CartPricesDto $prices, Money $couponDiscount, Money $pointsDiscount, Money $shipping): TotalsDto
    {
        if ($prices->unpriced !== []) {
            throw new LinesWithoutPrice;
        }

        foreach ([$couponDiscount, $pointsDiscount, $shipping] as $amount) {
            if ($amount->currencyCode !== $prices->currencyCode || $amount->isNegative()) {
                throw new AmountsInvalid;
            }
        }

        if ($couponDiscount->add($pointsDiscount)->compareTo($prices->netSubtotal) > 0) {
            throw new AmountsInvalid;
        }

        $goodsTotal = $prices->netSubtotal->subtract($couponDiscount)->subtract($pointsDiscount);
        $taxableBase = $goodsTotal->add($shipping);
        $vat = $taxableBase->multiply(self::rate($prices->taxRateBasisPoints), RoundingMode::HalfUp);

        return new TotalsDto(
            grossSubtotal: $prices->grossSubtotal,
            netSubtotal: $prices->netSubtotal,
            couponDiscount: $couponDiscount,
            pointsDiscount: $pointsDiscount,
            goodsTotal: $goodsTotal,
            shipping: $shipping,
            taxableBase: $taxableBase,
            vat: $vat,
            orderTotal: $taxableBase->add($vat),
            taxRateBasisPoints: $prices->taxRateBasisPoints,
        );
    }

    /** Basis points as an exact decimal factor: 1500 is 0.1500. */
    private static function rate(int $basisPoints): string
    {
        if ($basisPoints < 0) {
            throw new AmountsInvalid;
        }

        return sprintf('%d.%04d', intdiv($basisPoints, 10000), $basisPoints % 10000);
    }
}
