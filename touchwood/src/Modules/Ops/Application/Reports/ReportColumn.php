<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

/** One column of a report's sheet: its key, its header in the reader's language, what it holds. */
final readonly class ReportColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public ColumnType $type,
    ) {}
}
