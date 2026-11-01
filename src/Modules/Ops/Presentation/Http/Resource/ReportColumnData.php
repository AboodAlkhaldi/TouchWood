<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One column of a report's sheet (ops.md §4): its key, its header in the reader's language, and what it
 * holds - `text`, `count`, `money` (a decimal string in the row's currency, or null where there is no
 * figure), `day` (`Y-m-d`) or `month` (`Y-m`).
 */
#[TypeScript]
final class ReportColumnData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
    ) {}
}
