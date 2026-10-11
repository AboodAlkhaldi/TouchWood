<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Service;

use Brick\Math\BigInteger;
use Brick\Math\Exception\IntegerOverflowException;
use Brick\Math\RoundingMode;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;

/**
 * The points programme's arithmetic (loyalty.md §1.5), in the store currency's smallest unit —
 * halalas, piastres, fils — and whole points only, never a float (handoff §5.1). `$exponent` is the
 * store currency's (2 for a currency counted in hundredths), read from its currencies row; `ppu` is
 * points_per_unit_off.
 *
 * Products are worked in BigInteger, so an amount times a rate never passes 64 bits on the way; a
 * result that would is refused as the domain's own error, never the library's.
 */
final class PointsArithmetic
{
    /**
     * Points earned on an order: floor(goods_total × earn_per_unit ÷ 10^e). 1,234.56 in a 2-decimal
     * currency earns 1,234 points at 1 a unit.
     */
    public static function earned(int $goodsTotalMinor, int $earnPerUnit, int $exponent): int
    {
        self::notNegative($goodsTotalMinor, 'amount');
        self::positive($earnPerUnit);

        return self::whole(BigInteger::of($goodsTotalMinor)->multipliedBy($earnPerUnit)->dividedBy(self::unit($exponent), RoundingMode::Floor));
    }

    /**
     * What P points are worth: floor(P × 10^e ÷ ppu).
     */
    public static function worth(int $points, int $pointsPerUnitOff, int $exponent): int
    {
        self::notNegative($points, 'points');
        self::positive($pointsPerUnitOff);

        return self::whole(BigInteger::of($points)->multipliedBy(self::unit($exponent))->dividedBy($pointsPerUnitOff, RoundingMode::Floor));
    }

    /**
     * The most of an order points may pay: floor(net_subtotal × max_redemption_percent ÷ 100).
     */
    public static function cap(int $netSubtotalMinor, int $maxRedemptionPercent): int
    {
        self::notNegative($netSubtotalMinor, 'amount');

        if ($maxRedemptionPercent < 0 || $maxRedemptionPercent > 100) {
            throw new InvalidPointsAttribute('percent', 'must be from 0 to 100');
        }

        return self::whole(BigInteger::of($netSubtotalMinor)->multipliedBy($maxRedemptionPercent)->dividedBy(100, RoundingMode::Floor));
    }

    /**
     * The most points the cap lets count: floor(((cap + 1) × ppu − 1) ÷ 10^e) — the largest P whose
     * worth does not pass the cap.
     */
    public static function mostPointsUnderCap(int $capMinor, int $pointsPerUnitOff, int $exponent): int
    {
        self::notNegative($capMinor, 'amount');
        self::positive($pointsPerUnitOff);

        return self::whole(BigInteger::of($capMinor)->plus(1)->multipliedBy($pointsPerUnitOff)->minus(1)->dividedBy(self::unit($exponent), RoundingMode::Floor));
    }

    /**
     * The points charged for a discount D: ceil(D × ppu ÷ 10^e) — the fewest points worth D, so a
     * customer is never charged a point that bought nothing; any other point stays with them.
     */
    public static function pointsCharged(int $discountMinor, int $pointsPerUnitOff, int $exponent): int
    {
        self::notNegative($discountMinor, 'amount');
        self::positive($pointsPerUnitOff);

        return self::whole(BigInteger::of($discountMinor)->multipliedBy($pointsPerUnitOff)->dividedBy(self::unit($exponent), RoundingMode::Ceiling));
    }

    /** 10^e, the smallest units in one unit of the currency: 0 to 6 decimals, as Platform's currencies allow. */
    private static function unit(int $exponent): BigInteger
    {
        if ($exponent < 0 || $exponent > 6) {
            throw new InvalidPointsAttribute('currency', 'its exponent must be from 0 to 6');
        }

        return BigInteger::ten()->power($exponent);
    }

    private static function whole(BigInteger $result): int
    {
        try {
            return $result->toInt();
        } catch (IntegerOverflowException) {
            throw new InvalidPointsAttribute('amount', 'too large to count');
        }
    }

    private static function notNegative(int $value, string $attribute): void
    {
        if ($value < 0) {
            throw new InvalidPointsAttribute($attribute, 'must not be below 0');
        }
    }

    /** A rate of the programme: at least 1, as its setting allows (§1.5). */
    private static function positive(int $rate): void
    {
        if ($rate < 1) {
            throw new InvalidPointsAttribute('rate', 'must be at least 1');
        }
    }
}
