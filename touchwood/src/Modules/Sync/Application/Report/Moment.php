<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A moment as the screens get every moment (pricing.md §4.3.1 "Moments"): ISO 8601, written in the
 * store's zone by the page.
 */
final class Moment
{
    public static function iso(?string $stored): ?string
    {
        return $stored === null ? null : CarbonImmutable::parse($stored)->format(DateTimeInterface::ATOM);
    }

    /** Seconds from one moment to the other; null until both are known. */
    public static function took(?string $from, ?string $to): ?int
    {
        return $from === null || $to === null ? null : max(0, CarbonImmutable::parse($to)->getTimestamp() - CarbonImmutable::parse($from)->getTimestamp());
    }
}
