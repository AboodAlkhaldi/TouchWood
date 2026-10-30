<?php

declare(strict_types=1);

namespace Modules\Ops\Application\DailyReport;

use Shared\Domain\ValueObject\Money;

/**
 * The three figures the daily report compares (ops.md §1.11 items 1 and 10): orders that count, revenue
 * without VAT, new customers — for today, yesterday's same hours, or the month so far.
 */
final readonly class PeriodFigures
{
    public function __construct(
        /** Orders placed in the period that count: never cancelled, rejected or come back (§1.10). */
        public int $orders,
        /**
         * What their customers pay for the goods after the coupon and the points, without VAT or shipping,
         * **less the refunds of the returns asked in the period and completed** (§1.10).
         */
        public Money $revenue,
        /** Accounts registered in the store in the period, individuals and companies together. */
        public int $newCustomers,
    ) {}
}
