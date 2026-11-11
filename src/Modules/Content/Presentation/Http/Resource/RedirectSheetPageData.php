<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** W11b · the checked sheet (content.md §4.4): every line with what Apply would do, the counts per result, applied or not. */
#[TypeScript]
final class RedirectSheetPageData extends Data
{
    /**
     * @param  list<SheetLineData>  $lines
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public string $uploadId,
        public array $lines,
        public array $counts,
        public bool $applied,
        /** The result whose lines are shown: `NEW`, `SAME`, `CHANGE` or `REFUSED`. */
        public string $tab,
    ) {}
}
