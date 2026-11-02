<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One sheet of a report's preview (ops.md §4.6): its name, columns and notes, its first rows - one value per
 * column, an amount as a decimal string with Latin digits - every total row, shaped as the rows and never among
 * them, and whether the file holds more rows than these.
 */
#[TypeScript]
final class ReportSheetData extends Data
{
    /**
     * @param  list<ReportColumnData>  $columns
     * @param  list<string>  $notes
     * @param  list<list<string|int|null>>  $rows
     * @param  list<list<string|int|null>>  $totals
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $notes,
        public array $rows,
        public array $totals,
        public bool $more,
    ) {}
}
