<?php

declare(strict_types=1);

namespace Modules\Ops\Application\DailyReport;

/** Turns a daily report into its PDF (ops.md §1.11, R4-5), in the reader's language. */
interface DailyReportRenderer
{
    /** The PDF's bytes; `locale` is `ar` (right to left) or `en`. */
    public function render(DailyReport $report, string $locale): string;
}
