<?php

declare(strict_types=1);

namespace Modules\Ops\Application\DailyReport;

use DateTimeImmutable;

/**
 * A daily report as made (ops.md §1.11): one store's, or every store's with the totals per currency after
 * them - never converted (§1.10, round 3 #4).
 */
final readonly class DailyReport
{
    /**
     * @param  non-empty-list<StoreDayReport>  $stores
     * @param  list<CurrencyTotals>  $totals  empty for one store
     */
    public function __construct(
        /** The moment it was made. */
        public DateTimeImmutable $madeAt,
        /** The time zone its header is read in: the store's, or the all-stores report's own (§1.11). */
        public string $timezone,
        public bool $allStores,
        public array $stores,
        public array $totals,
    ) {}
}
