<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Service;

use InvalidArgumentException;
use LogicException;
use Shared\Domain\ValueObject\Money;

/**
 * The shares of an order's discounts and VAT, line by line (sales.md §1.8), fixed at placement and after
 * every edit, so returning everything refunds exactly `order_total` — no halala lost (handoff §5.1):
 *
 * 1. the coupon's — Promotions' answer, one a line, as given;
 * 2. the points' — the points discount spread with `Money::allocate` over the lines by their total less
 *    their coupon share, so no line's goods share goes below 0;
 * 3. the VAT's — the order's VAT spread over the lines' taxable amounts (total less both shares) and the
 *    shipping fee, in proportion; the shipping's part apart.
 *
 * None of a kind when its amount is 0. Pure: no database, no clock.
 */
final class OrderShares
{
    /**
     * @param  list<Money>  $lineTotals  each line's total, a gift's 0
     * @param  list<Money>  $couponShares  one a line, in the same order
     * @return array{lines: list<array{coupon: Money, points: Money, vat: Money}>, shippingVat: Money}
     *
     * @throws InvalidArgumentException when the lists do not line up, a coupon share passes its line, an
     *                                  amount is below 0, or the points discount passes what the coupon left of the lines
     */
    public static function of(array $lineTotals, array $couponShares, Money $pointsDiscount, Money $vat, Money $shippingFee): array
    {
        if ($lineTotals === [] || count($lineTotals) !== count($couponShares)) {
            throw new InvalidArgumentException('One coupon share a line, and at least one line.');
        }

        if ($pointsDiscount->isNegative() || $vat->isNegative() || $shippingFee->isNegative()) {
            throw new InvalidArgumentException('An amount below 0.');
        }

        $zero = Money::zero($vat->currencyCode);
        $afterCoupon = [];

        foreach ($lineTotals as $index => $total) {
            $left = $total->subtract($couponShares[$index]);

            if ($left->isNegative() || $couponShares[$index]->isNegative()) {
                throw new InvalidArgumentException('A coupon share is below 0 or above its line.');
            }

            $afterCoupon[] = $left;
        }

        if ($pointsDiscount->compareTo(array_reduce($afterCoupon, static fn (Money $sum, Money $left): Money => $sum->add($left), $zero)) > 0) {
            throw new InvalidArgumentException('More points discount than the coupon left of the lines.');
        }

        $points = self::spread($pointsDiscount, array_map(static fn (Money $left): int => $left->minorUnits, $afterCoupon), $zero, 'points');
        $taxable = [];

        foreach ($afterCoupon as $index => $left) {
            $taxable[] = $left->subtract($points[$index]);
        }

        $vatShares = self::spread($vat, [...array_map(static fn (Money $amount): int => $amount->minorUnits, $taxable), $shippingFee->minorUnits], $zero, 'VAT');
        $shippingVat = array_pop($vatShares) ?? $zero;

        $lines = [];

        foreach ($lineTotals as $index => $total) {
            $lines[] = ['coupon' => $couponShares[$index], 'points' => $points[$index], 'vat' => $vatShares[$index]];
        }

        return ['lines' => $lines, 'shippingVat' => $shippingVat];
    }

    /**
     * @param  list<int>  $ratios
     * @return list<Money>
     */
    private static function spread(Money $amount, array $ratios, Money $zero, string $what): array
    {
        if ($amount->isZero()) {
            return array_fill(0, count($ratios), $zero);
        }

        if (array_sum($ratios) <= 0) {
            throw new LogicException("A {$what} amount above 0 with nothing to spread it over.");
        }

        return $amount->allocate(...$ratios);
    }
}
