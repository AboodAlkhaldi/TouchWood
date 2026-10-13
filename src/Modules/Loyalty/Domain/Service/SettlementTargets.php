<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Service;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Model\Settlement\OrderPointsFacts;

/**
 * How many points a settlement moves (loyalty.md §1.8, "How much"): a cancellation settles
 * everything; a return and an edit settle **by running totals** — the target so far is
 * floor(the order's points × returned so far ÷ the order's total), and a settlement moves the target
 * less what earlier ones moved, so an order returned in parts lands exactly on its points. A total of
 * 0 moves nothing, and nothing divides by it.
 */
final class SettlementTargets
{
    /**
     * A cancellation, before shipping: every point it used comes back; it earned nothing.
     *
     * @return array{int, int} points to give back, points to take back
     */
    public static function cancellation(OrderPointsFacts $facts): array
    {
        return [$facts->pointsRedeemed - $facts->pointsGivenBack, 0];
    }

    /**
     * A return after delivery, of these shares of `goods_total` and of the points discount; the
     * shares join the order's returned amounts.
     *
     * @return array{int, int} points to give back, points to take back
     */
    public static function return(OrderPointsFacts $facts, int $goodsShareMinor, int $discountShareMinor): array
    {
        if ($goodsShareMinor < 0 || $discountShareMinor < 0
            || $facts->returnedGoodsMinor + $goodsShareMinor > ($facts->goodsTotalMinor ?? 0)
            || $facts->returnedDiscountMinor + $discountShareMinor > $facts->discountMinor) {
            throw new InvalidPointsAttribute('returned_amounts', 'past the order\'s');
        }

        $facts->returnedGoodsMinor += $goodsShareMinor;
        $facts->returnedDiscountMinor += $discountShareMinor;

        return [
            self::share($facts->pointsRedeemed, $facts->returnedDiscountMinor, $facts->discountMinor) - $facts->pointsGivenBack,
            self::share($facts->pointsEarned, $facts->returnedGoodsMinor, $facts->goodsTotalMinor ?? 0) - $facts->takenBack(),
        ];
    }

    /**
     * A staff edit before shipping that lowers the points discount to `$newDiscountMinor` (§1.8): the
     * points above it come back, as a return of the difference; nothing is taken back.
     *
     * @return int points to give back
     */
    public static function edit(OrderPointsFacts $facts, int $newDiscountMinor): int
    {
        $now = $facts->discountMinor - $facts->returnedDiscountMinor;

        if ($newDiscountMinor < 0 || $newDiscountMinor > $now) {
            throw new InvalidPointsAttribute('returned_amounts', 'a points discount above the order\'s');
        }

        $facts->returnedDiscountMinor += $now - $newDiscountMinor;

        return self::share($facts->pointsRedeemed, $facts->returnedDiscountMinor, $facts->discountMinor) - $facts->pointsGivenBack;
    }

    /**
     * What a staff edit leaves the points to pay (§1.8): the least of the discount now, the cap on
     * the edited net subtotal by the percent the order redeemed under, and the room the coupon left.
     *
     * @return array{int, int} the points discount after the edit, the points still charged
     */
    public static function afterEdit(OrderPointsFacts $facts, int $capPercent, int $editedNetSubtotalMinor, int $roomMinor): array
    {
        $now = $facts->discountMinor - $facts->returnedDiscountMinor;
        $discount = max(0, min($now, PointsArithmetic::cap($editedNetSubtotalMinor, $capPercent), $roomMinor));
        $returned = $facts->returnedDiscountMinor + $now - $discount;

        return [$discount, $facts->pointsRedeemed - self::share($facts->pointsRedeemed, $returned, $facts->discountMinor)];
    }

    /** floor(whole × part ÷ total); 0 when the total is 0. */
    private static function share(int $whole, int $part, int $total): int
    {
        return $total === 0 ? 0 : BigInteger::of($whole)->multipliedBy($part)->dividedBy($total, RoundingMode::Floor)->toInt();
    }
}
