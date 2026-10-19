<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query;

use DateTimeImmutable;
use DateTimeZone;

/**
 * "Expiring next" (loyalty.md §4.1): the points of every live lot whose date falls on the soonest
 * calendar day in the store's time zone, summed, and that day. A lot ends at a moment, and several
 * can end on one day - showing only the first would say fewer points than will go.
 */
final readonly class ExpiringNext
{
    /**
     * @param  string  $day  the day in the store's zone, YYYY-MM-DD
     */
    public function __construct(
        public int $points,
        public string $day,
    ) {}

    /**
     * @param  list<array{0: int, 1: DateTimeImmutable}>  $lots  each live lot's points left and its
     *                                                           moment of expiry, in any order
     */
    public static function of(array $lots, string $timezone): ?self
    {
        $zone = new DateTimeZone($timezone);
        $byDay = [];

        foreach ($lots as [$points, $expiresAt]) {
            $day = $expiresAt->setTimezone($zone)->format('Y-m-d');
            $byDay[$day] = ($byDay[$day] ?? 0) + $points;
        }

        if ($byDay === []) {
            return null;
        }

        ksort($byDay, SORT_STRING);
        $day = (string) array_key_first($byDay);

        return new self($byDay[$day], $day);
    }
}
