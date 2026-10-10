<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Service;

use Brick\Math\RoundingMode;
use Modules\Pricing\Domain\Exception\PercentOutOfRange;
use Shared\Domain\ValueObject\Money;

/**
 * A price after a percentage off (pricing.md §1.3, §1.5), the percentage in basis points (1–9999:
 * above 0 and below 100 %). The price that results is rounded half up to the smallest coin - the
 * halala, piastre or fils (owner, 2026-10-08) - once, so what the shopper pays is exactly what is
 * stored.
 */
final class PercentOff
{
    public static function price(Money $price, int $basisPoints): Money
    {
        if ($basisPoints < 1 || $basisPoints > 9999) {
            throw new PercentOutOfRange;
        }

        // What stays, as an exact decimal: 1500 basis points off leaves 0.8500 of the price.
        $remaining = sprintf('0.%04d', 10000 - $basisPoints);

        return $price->multiply($remaining, RoundingMode::HalfUp);
    }
}
