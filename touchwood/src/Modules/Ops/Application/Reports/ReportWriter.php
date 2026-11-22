<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

/** Writes a report as an Excel workbook (ops.md §1.10, owner R4-5), row by row as its sheets give them. */
interface ReportWriter
{
    /** Writes the workbook to `$path`, replacing whatever is there. */
    public function write(Report $report, string $path): void;
}
