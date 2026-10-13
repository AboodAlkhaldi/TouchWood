<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Throwable;

/**
 * A sale's form as staff fill it (pricing.md §1.3): a percentage as typed - up to two decimals, read
 * into basis points - and its dates.
 */
final class SaleInput
{
    /**
     * @throws InvalidPricingInput
     */
    public static function percent(string $percent): int
    {
        if (preg_match('/\A(\d{1,2})(?:\.(\d{1,2}))?\z/', trim($percent), $parts) !== 1) {
            throw new InvalidPricingInput('percent');
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    /**
     * A start that is not given, or already past, is now: a sale is never back-dated.
     *
     * @throws InvalidPricingInput
     */
    public static function start(?string $startsAt, DateTimeImmutable $now): DateTimeImmutable
    {
        $start = $startsAt === null ? $now : self::moment($startsAt, 'starts_at');

        return $start < $now ? $now : $start;
    }

    /**
     * @throws InvalidPricingInput
     */
    public static function end(?string $endsAt): ?DateTimeImmutable
    {
        return $endsAt === null ? null : self::moment($endsAt, 'ends_at');
    }

    private static function moment(string $value, string $field): DateTimeImmutable
    {
        try {
            return CarbonImmutable::parse($value)->toDateTimeImmutable();
        } catch (Throwable) {
            throw new InvalidPricingInput($field);
        }
    }
}
