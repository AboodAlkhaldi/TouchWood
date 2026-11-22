<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

/** One of the store's own days of money moved, by way (`SalesReports::moneyByDay`; ops.md §1.10). */
final readonly class MoneyDayDto
{
    /**
     * @param  list<MoneyByWayDto>  $payments
     * @param  list<MoneyByWayDto>  $refunds
     */
    public function __construct(
        /** The store's own day, `Y-m-d`. */
        public string $day,
        public array $payments,
        public array $refunds,
    ) {}
}
