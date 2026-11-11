<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** W11 · Redirects (content.md §4.4): the lines, most visited first, 50 at a time, the search, whether a check waits. */
#[TypeScript]
final class RedirectsPageData extends Data
{
    /**
     * @param  list<RedirectRowData>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
        public string $q,
        public bool $checkWaits,
    ) {}
}
