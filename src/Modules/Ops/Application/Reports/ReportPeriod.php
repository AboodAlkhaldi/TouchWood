<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\ReportRangeRefused;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * The days a report covers, both included (ops.md §1.10: "a date range"). A day is read in each store's
 * own time zone - its midnight to the midnight after the last day - so every store of an all-stores
 * report covers its own days, as the daily report's sections do (§1.11).
 *
 * **Never past the moment the report was asked for**: a range that includes today ends at that moment,
 * the same for every page of every sheet, so a report read in pages counts the same orders throughout -
 * and says it ran "until" then.
 *
 * Days from 2020 to the end of next year; at most five years at once, twelve months for searches.
 */
final readonly class ReportPeriod
{
    public const int FIRST_YEAR = 2020;

    public const int MOST_YEARS = 5;

    public const int MOST_SEARCH_MONTHS = 12;

    private function __construct(
        public string $fromDay,
        public string $toDay,
        public DateTimeImmutable $now,
    ) {}

    /** @throws InvalidOpsInput|ReportRangeRefused */
    public static function of(?string $from, ?string $to, DateTimeImmutable $now, bool $searches = false): self
    {
        $from = self::day($from, 'from');
        $to = self::day($to, 'to');
        if ($to < $from) {
            throw new InvalidOpsInput('to', 'before the first day');
        }

        $lastYear = (int) $now->format('Y') + 1;
        if ((int) substr($from, 0, 4) < self::FIRST_YEAR) {
            throw new ReportRangeRefused('from', ReportRangeRefused::YEARS);
        }
        if ((int) substr($to, 0, 4) > $lastYear) {
            throw new ReportRangeRefused('to', ReportRangeRefused::YEARS);
        }

        $limit = new DateTimeImmutable($from)->modify($searches ? '+'.self::MOST_SEARCH_MONTHS.' months' : '+'.self::MOST_YEARS.' years');
        if (new DateTimeImmutable($to) >= $limit) {
            throw new ReportRangeRefused('to', $searches ? ReportRangeRefused::SEARCHES_TOO_LONG : ReportRangeRefused::TOO_LONG);
        }

        return new self($from, $to, $now);
    }

    /** The first day's midnight in the store - or the moment asked, for a range that starts later. */
    public function start(StoreDto $store): DateTimeImmutable
    {
        $start = new DateTimeImmutable($this->fromDay.' 00:00:00', new DateTimeZone($store->timezone));

        return min($start, $this->now->setTimezone(new DateTimeZone($store->timezone)));
    }

    /** The midnight after the last day in the store, or the moment asked when that comes first: not included. */
    public function end(StoreDto $store): DateTimeImmutable
    {
        return min($this->fullEnd($store), $this->now->setTimezone(new DateTimeZone($store->timezone)));
    }

    /** How many days the range names, both included. */
    public function days(): int
    {
        return (int) new DateTimeImmutable($this->fromDay)->diff(new DateTimeImmutable($this->toDay))->days + 1;
    }

    /** Whether the range reaches past the moment asked in any of the stores: it then runs "until" it. */
    public function cutAtNow(StoreDto ...$stores): bool
    {
        foreach ($stores as $store) {
            if ($this->fullEnd($store) > $this->now) {
                return true;
            }
        }

        return false;
    }

    /**
     * The store's days or months in the range up to the moment asked, each with its first and last day.
     *
     * @return list<array{string, DateTimeImmutable, DateTimeImmutable}> the day (`Y-m-d`) or month (`Y-m`), first day, last day
     */
    public function periods(StoreDto $store, bool $byMonth): array
    {
        $zone = new DateTimeZone($store->timezone);
        $first = new DateTimeImmutable($this->fromDay.' 00:00:00', $zone);
        $last = new DateTimeImmutable($this->end($store)->modify('-1 second')->format('Y-m-d').' 00:00:00', $zone);
        $periods = [];

        for ($day = $first; $day <= $last; $day = $byMonth ? $day->modify('first day of next month') : $day->modify('+1 day')) {
            $end = $byMonth ? min($day->modify('last day of this month'), $last) : $day;
            $periods[] = [$day->format($byMonth ? 'Y-m' : 'Y-m-d'), $day, $end];
        }

        return $periods;
    }

    private function fullEnd(StoreDto $store): DateTimeImmutable
    {
        return new DateTimeImmutable($this->toDay.' 00:00:00', new DateTimeZone($store->timezone))->modify('+1 day');
    }

    /** @throws InvalidOpsInput */
    private static function day(?string $value, string $attribute): string
    {
        $day = $value === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $day !== false && $day->format('Y-m-d') === $value ? $value : throw new InvalidOpsInput($attribute, 'not a day');
    }
}
