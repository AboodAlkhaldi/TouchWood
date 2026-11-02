<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ViewReport;

use Modules\Ops\Application\Reports\Report;
use Modules\Ops\Application\Reports\ReportBuilder;
use Modules\Ops\Application\Reports\ReportReaders;
use Modules\Ops\Application\Reports\ReportSheet;
use Modules\Ops\Application\Reports\ReportTotal;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\ReportRangeRefused;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * The reports page's preview (ops.md §1.10, §4), under `ops.report.view` in the store - Stock also
 * `inventory.stock.manage` - every store's a super admin's alone: each sheet's first rows, and its totals.
 *
 * A sheet's totals come last and add up every row, so a sheet that has them is walked to its end, one page at a
 * time, keeping only the rows shown and the totals: its length never sits in memory. A sheet without them is
 * read no further than the rows shown.
 */
final readonly class ViewReportHandler
{
    public const int ROWS = 50;

    public function __construct(
        private Authorizer $authorizer,
        private ReportReaders $readers,
        private ReportBuilder $reports,
    ) {}

    /** @throws Unauthorized|InvalidOpsInput|ReportRangeRefused */
    public function handle(ViewReport $query): ReportPreview
    {
        $ask = $this->readers->ask($query->report, $query->store);
        foreach ($ask->permissions as $permission) {
            $this->authorizer->authorize($permission, $ask->scope());
        }
        $report = $this->reports->build($ask, $query->from, $query->to, $query->by, self::ROWS);
        $sheets = [];
        $more = [];
        $totals = [];

        foreach ($report->sheets as $sheet) {
            $rows = [];
            $itsTotals = [];
            $itsMore = false;
            foreach ($sheet->rows as $row) {
                if ($row instanceof ReportTotal) {
                    $itsTotals[] = $row->values;
                } elseif (count($rows) < self::ROWS) {
                    $rows[] = $row;
                } else {
                    $itsMore = true;
                    if (! $sheet->hasTotals) {
                        break;
                    }
                }
            }
            $sheets[] = new ReportSheet($sheet->name, $sheet->columns, $sheet->notes, $rows, $sheet->hasTotals);
            $more[] = $itsMore;
            $totals[] = $itsTotals;
        }

        return new ReportPreview(new Report($report->kind, $report->locale, $report->title, $report->subtitle, $sheets, $report->filename), $more, $totals);
    }
}
