<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\DownloadDailyReport;

/** A kept daily report's PDF and the name it is saved under. */
final readonly class DailyReportDownload
{
    public function __construct(
        public string $filename,
        public string $pdf,
    ) {}
}
