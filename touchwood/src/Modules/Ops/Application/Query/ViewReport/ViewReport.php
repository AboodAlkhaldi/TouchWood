<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ViewReport;

/**
 * A report's first rows, as the reports page previews them (ops.md §1.10, §4): the report by its name, a
 * store - or every store - its first and last days, and per day or month for Revenue.
 */
final readonly class ViewReport
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
