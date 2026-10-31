<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\DownloadReport;

use Modules\Ops\Application\Reports\ReportBuilder;
use Modules\Ops\Application\Reports\ReportReaders;
use Modules\Ops\Application\Reports\ReportWriter;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\ReportRangeRefused;
use RuntimeException;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Throwable;

/**
 * A report downloaded as Excel (ops.md §1.10, §3), under the preview's permissions: written row by row to a
 * temporary file - the sheet's rows are read as they are written, so ten thousand sizes never sit in
 * memory - which the caller sends and deletes. A report that fails half way leaves no file behind.
 */
final readonly class DownloadReportHandler
{
    /** How long a report's file may be left behind before a later download sweeps it. */
    public const int KEPT_SECONDS = 3600;

    public function __construct(
        private Authorizer $authorizer,
        private ReportReaders $readers,
        private ReportBuilder $reports,
        private ReportWriter $writer,
    ) {}

    /** @throws Unauthorized|InvalidOpsInput|ReportRangeRefused */
    public function handle(DownloadReport $query): ReportFile
    {
        $ask = $this->readers->ask($query->report, $query->store);
        foreach ($ask->permissions as $permission) {
            $this->authorizer->authorize($permission, $ask->scope());
        }
        $report = $this->reports->build($ask, $query->from, $query->to, $query->by);
        $path = tempnam(self::folder(), 'rep');
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

    /**
     * The reports' own temporary folder - nothing else is ever in it - with every file older than an hour
     * swept out: a download the connection dropped before it was sent, whose file was never deleted.
     */
    public static function folder(): string
    {
        $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'touchwood-reports';
        if (! is_dir($folder) && ! @mkdir($folder, 0700) && ! is_dir($folder)) {
            throw new RuntimeException('No temporary folder for the reports.');
        }

        foreach (glob($folder.DIRECTORY_SEPARATOR.'*') ?: [] as $old) {
            if (is_file($old) && filemtime($old) < time() - self::KEPT_SECONDS) {
                @unlink($old);
            }
        }

        return $folder;
    }
}
