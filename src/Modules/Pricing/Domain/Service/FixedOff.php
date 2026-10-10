<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Service;

use Shared\Domain\ValueObject\Money;

/**
 * A price after a fixed amount off (pricing.md §1.5). An amount that would take the price to 0 or
 * below skips the size: it keeps its other prices (owner, 2026-10-07) - answered as null.
 */
final class FixedOff
{
    public static function price(Money $price, Money $off): ?Money
    {
        $after = $price->subtract($off);

        return $after->isPositive() ? $after : null;
    }
}
