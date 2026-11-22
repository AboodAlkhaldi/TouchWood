<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ViewReport;

use Modules\Ops\Application\Reports\Report;
use Modules\Ops\Application\Reports\ReportMoney;

/** A report's sheets with no rows of their own; each one's first rows, whether it has more, and its totals apart (ops.md §4.6). */
final readonly class ReportPreview
{
    /**
     * @param  list<list<list<string|int|ReportMoney|null>>>  $rows  by sheet, in the report's order: the first rows
     * @param  list<bool>  $more  by sheet, in the report's order: rows past the first, totals aside
     * @param  list<list<list<string|int|ReportMoney|null>>>  $totals  by sheet, in the report's order
     */
    public function __construct(
        public Report $report,
        public array $rows,
        public array $more,
        public array $totals,
    ) {}
}
