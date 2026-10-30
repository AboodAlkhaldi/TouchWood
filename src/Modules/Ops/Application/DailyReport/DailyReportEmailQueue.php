<?php

declare(strict_types=1);

namespace Modules\Ops\Application\DailyReport;

use DateTimeImmutable;

/**
 * Queues the daily report's email (ops.md §1.11) for a moment, on behalf of the person acting - the one
 * who switched it on, or, inside the email's own job, the one it runs for - and its delivery once made.
 */
interface DailyReportEmailQueue
{
    public function send(string $settingId, int $version, DateTimeImmutable $at): void;

    public function deliver(string $reportId): void;
}
