<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A home table (dashboard.md §2 G, H; §6.1's "table"): rows with a photo, and the way to the whole list.
 */
#[TypeScript]
final readonly class HomeTable
{
    /**
     * @param  list<HomeColumn>  $columns  the first one heads the photo and name
     * @param  list<HomeTableRow>  $rows
     */
    public function __construct(
        public array $columns,
        public array $rows,
        /** Already in words: what an empty table says. */
        public string $empty,
    ) {}
}
