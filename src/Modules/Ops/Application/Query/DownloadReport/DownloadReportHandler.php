<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\DownloadReport;

use Modules\Ops\Application\Reports\ReportBuilder;
use Modules\Ops\Application\Reports\ReportWriter;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use RuntimeException;
use Shared\Application\Unauthorized;
use Throwable;

/**
 * A report downloaded as Excel (ops.md §1.10, §3), under the preview's permissions: written row by row to a
 * temporary file - the sheet's rows are read as they are written, so ten thousand sizes never sit in
 * memory - which the caller sends and deletes. A report that fails half way leaves no file behind.
 */
final readonly class DownloadReportHandler
{
    public function __construct(
        private ReportBuilder $reports,
        private ReportWriter $writer,
    ) {}

    /** @throws Unauthorized|InvalidOpsInput */
    public function handle(DownloadReport $query): ReportFile
    {
        $report = $this->reports->build($query->report, $query->store, $query->from, $query->to, $query->by);
        $path = tempnam(sys_get_temp_dir(), 'tw-report-');
        if ($path === false) {
            throw new RuntimeException('No temporary file for the report.');
        }

        try {
            $this->writer->write($report, $path);
        } catch (Throwable $failed) {
            @unlink($path);

            throw $failed;
        }

        return new ReportFile($path, $report->filename);
    }
}
