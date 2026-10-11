<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The Super Admin's products files (catalog.md §4.4 S11): newest first, a page at a time. */
#[TypeScript]
final class ImportsPage extends Data
{
    /**
     * @param  list<ImportFileRowData>  $files
     * @param  list<ImportProblemData>|null  $problems  the last upload's, when it was refused
     */
    public function __construct(
        public array $files,
        public int $page,
        public bool $more,
        public ?array $problems,
        /** How many problems the refused file had: the list holds the first 500. */
        public ?int $problemsTotal,
    ) {}
}
