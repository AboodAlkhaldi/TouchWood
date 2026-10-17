<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Service;

use Brick\Math\RoundingMode;
use Shared\Domain\ValueObject\Money;

/**
 * The room left for what a customer applies — a coupon, then points — under the store's discount
 * ceiling (promotions.md §1.6; handoff §11.3): `floor(gross × percent ÷ 100)` less what the prices
 * already took off (`gross − net`), never below 0. An order whose prices alone pass the ceiling sells;
 * it simply leaves no room.
 */
final class DiscountRoom
{
    public static function of(Money $grossSubtotal, Money $netSubtotal, int $ceilingPercent): Money
    {
        $most = $grossSubtotal->multiply(Percent::fraction($ceilingPercent), RoundingMode::Floor);
        $room = $most->subtract($grossSubtotal->subtract($netSubtotal));

        return $room->isNegative() ? Money::zero($grossSubtotal->currencyCode) : $room;
    }
}
