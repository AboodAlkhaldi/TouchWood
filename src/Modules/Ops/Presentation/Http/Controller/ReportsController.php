<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ops\Application\Query\DownloadReport\DownloadReport;
use Modules\Ops\Application\Query\DownloadReport\DownloadReportHandler;
use Modules\Ops\Application\Query\ViewReport\ViewReport;
use Modules\Ops\Application\Query\ViewReport\ViewReportHandler;
use Modules\Ops\Application\Reports\ReportColumn;
use Modules\Ops\Application\Reports\ReportMoney;
use Modules\Ops\Presentation\Http\Resource\ReportColumnData;
use Modules\Ops\Presentation\Http\Resource\ReportPreviewData;
use Modules\Ops\Presentation\Http\Resource\ReportSheetData;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
| The reports in the panel (ops.md §1.10, §3, §4): a report's preview as JSON, and its Excel file. The
| choices are the query's: `store` (a store's id, or `all`), `from` and `to` (days, `Y-m-d`) and, for
| Revenue, `by` (`day` or `month`). Who may read which is Ops' answer, never the routing's.
*/
final readonly class ReportsController
{
    public function show(Request $request, string $report, ViewReportHandler $view): JsonResponse
    {
        $choices = self::choices($request);
        $preview = $view->handle(new ViewReport($report, $choices['store'] ?? '', $choices['from'] ?? null, $choices['to'] ?? null, $choices['by'] ?? null));
        $sheets = [];
        foreach ($preview->report->sheets as $index => $sheet) {
            $rows = [];
            foreach ($sheet->rows as $row) {
                $rows[] = array_map(static fn (string|int|ReportMoney|null $value): string|int|null => $value instanceof ReportMoney ? $value->decimal() : $value, $row);
            }
            $sheets[] = new ReportSheetData(
                $sheet->name,
                array_map(static fn (ReportColumn $column): ReportColumnData => new ReportColumnData($column->key, $column->label, $column->type->value), $sheet->columns),
                $sheet->notes,
                $rows,
                $preview->more[$index] ?? false,
            );
        }

        return new JsonResponse(new ReportPreviewData(
            $preview->report->kind->value,
            $preview->report->locale,
            $preview->report->rightToLeft(),
            $preview->report->title,
            $preview->report->subtitle,
            $sheets,
            $preview->report->filename,
            route('ops.admin.reports.download', ['report' => $report, ...$choices], false),
        )->toArray());
    }

    public function download(Request $request, string $report, DownloadReportHandler $download): BinaryFileResponse
    {
        $choices = self::choices($request);
        $file = $download->handle(new DownloadReport($report, $choices['store'] ?? '', $choices['from'] ?? null, $choices['to'] ?? null, $choices['by'] ?? null));

        return response()->download($file->path, $file->filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /**
     * The choices the request makes, each a text or left out.
     *
     * @return array<'store'|'from'|'to'|'by', string>
     */
    private static function choices(Request $request): array
    {
        $choices = [];
        foreach (['store', 'from', 'to', 'by'] as $key) {
            $value = Inputs::text($request, $key);
            if ($value !== null) {
                $choices[$key] = $value;
            }
        }

        return $choices;
    }
}
