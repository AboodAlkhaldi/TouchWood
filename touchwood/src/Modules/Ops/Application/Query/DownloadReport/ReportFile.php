<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\DownloadReport;

/** A report written to a file of its own, to send and then delete: nothing is kept (ops.md §5). */
final readonly class ReportFile
{
    public function __construct(
        public string $path,
        public string $filename,
    ) {}
}
