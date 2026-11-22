<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * A moment as Pricing's tables keep it: to the whole second (`timestamp(0)`). PostgreSQL rounds a fraction
 * half up, so an offer ended "now" at 10:00:00.7 would be kept ending at 10:00:01 - still running when its
 * sizes are priced again a moment later, its price pushed to the shop's cards and nothing pushing it again
 * - and a timed task queued for 10:00:00.7 would never find the 10:00:01 it was kept as. Every start and
 * end Pricing writes is taken down to its second first (the review of Content PR 5).
 */
final class WholeSecond
{
    public static function of(DateTimeImmutable $moment): DateTimeImmutable
    {
        return CarbonImmutable::instance($moment)->startOfSecond()->toDateTimeImmutable();
    }

    public static function now(): DateTimeImmutable
    {
        return self::of(CarbonImmutable::now()->toDateTimeImmutable());
    }
}
