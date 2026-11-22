<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\DeliverDailyReport;

/** Emails a daily report the email made (ops.md §1.11), once. */
final readonly class DeliverDailyReport
{
    public function __construct(
        public string $reportId,
    ) {}
}
