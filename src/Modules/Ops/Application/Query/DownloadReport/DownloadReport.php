<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\DownloadReport;

/** A report as an Excel file (ops.md §1.10, R4-5): the same choices as its preview (`ViewReport`). */
final readonly class DownloadReport
{
    public function __construct(
        public string $report,
        /** A store's id, or `all` for every store's. */
        public string $store,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $by = null,
    ) {}
}
