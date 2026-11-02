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
 * No sheet is read past the rows shown. Its totals come after its rows: a sheet that ends within them has summed
 * them already, and one that goes on says how to read them apart (ReportSheet::$totals). A sheet with no rows shows
 * no total, not even at zero (owner, 2026-11-02), though the file writes one.
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
        $report = $this->reports->build($ask, $query->from, $query->to, $query->by);
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
                    break;
                }
            }
            if ($itsMore && $sheet->totals !== null) {
                $itsTotals = ($sheet->totals)();
            }
            $sheets[] = new ReportSheet($sheet->name, $sheet->columns, $sheet->notes, $rows);
            $more[] = $itsMore;
            $totals[] = $rows === [] ? [] : $itsTotals;
        }

        return new ReportPreview(new Report($report->kind, $report->locale, $report->title, $report->subtitle, $sheets, $report->filename), $more, $totals);
    }
}
