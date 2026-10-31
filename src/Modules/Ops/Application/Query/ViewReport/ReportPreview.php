<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ViewReport;

use Modules\Ops\Application\Reports\Report;

/** A report with each sheet's first rows only, and whether each has more (ops.md §4: preview rows). */
final readonly class ReportPreview
{
    /**
     * @param  list<bool>  $more  by sheet, in the report's order
     */
    public function __construct(
        public Report $report,
        public array $more,
    ) {}
}
