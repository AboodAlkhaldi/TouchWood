<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One sheet of a report's preview (ops.md §4): its name, columns and notes, its first rows - one value per
 * column, an amount as a decimal string with Latin digits - and whether the file holds more.
 */
#[TypeScript]
final class ReportSheetData extends Data
{
    /**
     * @param  list<ReportColumnData>  $columns
     * @param  list<string>  $notes
     * @param  list<list<string|int|null>>  $rows
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $notes,
        public array $rows,
        public bool $more,
    ) {}
}
