<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

/** A store's orders over a range, for Ops' daily report (`SalesReports::counts`; ops.md §1.11 item 2). */
final readonly class SalesCountsDto
{
    public function __construct(
        /** Placed in the range, whatever became of them. */
        public int $placed,
        /** Of those, the ones that count as sales: never cancelled, rejected or come back (ops.md §1.10). */
        public int $counted,
        /** Of those, by an individual's account and by a company's. */
        public int $individuals,
        public int $companies,
        /** Cancelled, rejected and come back in the range — orders placed any day. */
        public int $cancelled,
        public int $rejected,
        public int $cameBack,
    ) {}
}
