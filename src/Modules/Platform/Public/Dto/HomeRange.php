<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use DateTimeImmutable;

/**
 * A period of the admin home and the one before it (dashboard.md §3.3), in one store's clock: each from its first
 * moment (included) up to its last (not included), as the modules' reads take them. Made by `HomePeriod::range()`.
 */
final readonly class HomeRange
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public DateTimeImmutable $beforeFrom,
        public DateTimeImmutable $beforeTo,
        /** Its points are months (`Y-m`) rather than days (`Y-m-d`): the Year. */
        public bool $byMonth,
    ) {}

    /**
     * The points of the period, oldest first: each day it touches (`Y-m-d`), or each month for the Year (`Y-m`).
     *
     * @return list<string>
     */
    public function points(): array
    {
        return self::between($this->from, $this->to, $this->byMonth);
    }

    /**
     * The points of the period before, oldest first, so its n-th lines up with the period's n-th.
     *
     * @return list<string>
     */
    public function beforePoints(): array
    {
        return self::between($this->beforeFrom, $this->beforeTo, $this->byMonth);
    }

    /** The point a day (`Y-m-d`) falls on: the day itself, or its month for the Year. */
    public function pointOf(string $day): string
    {
        return $this->byMonth ? substr($day, 0, 7) : $day;
    }

    /** @return list<string> */
    private static function between(DateTimeImmutable $from, DateTimeImmutable $to, bool $byMonth): array
    {
        $points = [];
        $at = $byMonth ? $from->setTime(0, 0)->modify('first day of this month') : $from->setTime(0, 0);

        // A range ending at a midnight does not touch the day that midnight starts.
        while ($at < $to) {
            $points[] = $at->format($byMonth ? 'Y-m' : 'Y-m-d');
            $at = $at->modify($byMonth ? '+1 month' : '+1 day');
        }

        return $points;
    }
}
