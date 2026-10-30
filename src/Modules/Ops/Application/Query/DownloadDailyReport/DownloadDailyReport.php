<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\DownloadDailyReport;

/** Opens a kept daily report's PDF again, exactly as it was made (ops.md §1.11). */
final readonly class DownloadDailyReport
{
    public function __construct(
        public string $report,
    ) {}
}
