<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Enums;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Platform\Public\Dto\HomeRange;

/**
 * The periods the admin home reads its figures over (dashboard.md §3.3), each against the period before it of
 * the same length. The home opens on This Month (D9). The address carries the value (`/admin?period=month`).
 *
 * Every range is worked out in one store's own clock (its time zone): a midnight is that store's midnight.
 */
enum HomePeriod: string
{
    case Today = 'today';
    case SevenDays = '7days';
    case Month = 'month';
    case ThreeMonths = '3months';
    case Year = 'year';

    /** What the home opens on (dashboard.md §3.3, D9). */
    public const self OPENS_ON = self::Month;

    /**
     * The period up to `$now` and the one before it, in `$zone`'s clock:
     *
     * - Today: midnight to now, against yesterday from midnight to the same time;
     * - 7 days: today and the 6 days before, against the 7 days before those, to the same time;
     * - This month: the 1st to now, against the month before from its 1st to the same day and time - the
     *   last day of a shorter month when it has fewer days;
     * - 3 months: this month and the two before it, against the three months before those, to the same point;
     * - Year: 1 January to now, against the year before to the same day and time.
     */
    public function range(DateTimeImmutable $now, DateTimeZone $zone): HomeRange
    {
        $now = $now->setTimezone($zone);
        $midnight = $now->setTime(0, 0);
        $first = $midnight->modify('first day of this month');

        return match ($this) {
            self::Today => new HomeRange($midnight, $now, $midnight->modify('-1 day'), $now->modify('-1 day'), false),
            self::SevenDays => new HomeRange($midnight->modify('-6 days'), $now, $midnight->modify('-13 days'), $now->modify('-7 days'), false),
            self::Month => new HomeRange($first, $now, $first->modify('-1 month'), self::monthsBack($now, 1), false),
            self::ThreeMonths => new HomeRange($first->modify('-2 months'), $now, $first->modify('-5 months'), self::monthsBack($now, 3), false),
            self::Year => new HomeRange($midnight->setDate((int) $now->format('Y'), 1, 1), $now, $midnight->setDate((int) $now->format('Y') - 1, 1, 1), self::monthsBack($now, 12), true),
        };
    }

    /**
     * The same day and time `$months` months before, in the same clock - the last day of that month when it
     * has fewer days (31 March, a month back, is 28 or 29 February, never 3 March).
     */
    private static function monthsBack(DateTimeImmutable $now, int $months): DateTimeImmutable
    {
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n') - $months;

        while ($month < 1) {
            $month += 12;
            $year--;
        }

        $days = (int) $now->setDate($year, $month, 1)->format('t');

        return $now->setDate($year, $month, min((int) $now->format('j'), $days));
    }
}
