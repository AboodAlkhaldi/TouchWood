<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * The days a report covers, both included (ops.md §1.10: "a date range"). A day is read in each store's
 * own time zone - its midnight to the midnight after the last day - so every store of an all-stores
 * report covers its own days, as the daily report's sections do (§1.11).
 */
final readonly class ReportPeriod
{
    private function __construct(
        public string $fromDay,
        public string $toDay,
    ) {}

    /** @throws InvalidOpsInput */
    public static function of(?string $from, ?string $to): self
    {
        $from = self::day($from, 'from');
        $to = self::day($to, 'to');

        return $to >= $from ? new self($from, $to) : throw new InvalidOpsInput('to', 'before the first day');
    }

    /** The first day's midnight in the store. */
    public function start(StoreDto $store): DateTimeImmutable
    {
        return new DateTimeImmutable($this->fromDay.' 00:00:00', new DateTimeZone($store->timezone));
    }

    /** The midnight after the last day, in the store: not included. */
    public function end(StoreDto $store): DateTimeImmutable
    {
        return new DateTimeImmutable($this->toDay.' 00:00:00', new DateTimeZone($store->timezone))->modify('+1 day');
    }

    /** Whether the days fit in twelve months - the search log keeps no more (§1.10, §7). */
    public function withinTwelveMonths(): bool
    {
        return new DateTimeImmutable($this->toDay) < new DateTimeImmutable($this->fromDay)->modify('+12 months');
    }

    /** @throws InvalidOpsInput */
    private static function day(?string $value, string $attribute): string
    {
        $day = $value === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $day !== false && $day->format('Y-m-d') === $value ? $value : throw new InvalidOpsInput($attribute, 'not a day');
    }
}
