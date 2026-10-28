<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

/** The money a store took and gave back over some days, by way (`SalesReports::moneyOn`; ops.md §1.11 item 3). */
final readonly class MoneyDaysDto
{
    /**
     * @param  list<MoneyByWayDto>  $payments
     * @param  list<MoneyByWayDto>  $refunds
     */
    public function __construct(
        public array $payments,
        public array $refunds,
    ) {}
}
