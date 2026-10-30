<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ViewReport;

use Modules\Ops\Application\Reports\Report;
use Modules\Ops\Application\Reports\ReportBuilder;
use Modules\Ops\Application\Reports\ReportSheet;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Shared\Application\Unauthorized;

/**
 * The reports page's preview (ops.md §1.10, §4), under `ops.report.view` in the store - Stock also
 * `inventory.stock.manage` - every store's a super admin's alone: each sheet's first rows, read no further.
 */
final readonly class ViewReportHandler
{
    public const int ROWS = 50;

    public function __construct(
        private ReportBuilder $reports,
    ) {}

    /** @throws Unauthorized|InvalidOpsInput */
    public function handle(ViewReport $query): ReportPreview
    {
        $report = $this->reports->build($query->report, $query->store, $query->from, $query->to, $query->by);
        $sheets = [];
        $more = [];

        foreach ($report->sheets as $sheet) {
            $rows = [];
            $more[] = false;
            foreach ($sheet->rows as $row) {
                if (count($rows) === self::ROWS) {
                    $more[array_key_last($more)] = true;
                    break;
                }
                $rows[] = $row;
            }
            $sheets[] = new ReportSheet($sheet->name, $sheet->columns, $sheet->notes, $rows);
        }

        return new ReportPreview(new Report($report->kind, $report->locale, $report->title, $report->subtitle, $sheets, $report->filename), $more);
    }
}
