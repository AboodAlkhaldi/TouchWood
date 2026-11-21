<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of a home table (dashboard.md §2 G, H): its first column - a photo, a name and a line under it - and
 * the cells after, in the columns' order.
 */
#[TypeScript]
final readonly class HomeTableRow
{
    /**
     * @param  list<HomeCell>  $cells  one per column after the first
     */
    public function __construct(
        /** Already in words: a product's name. */
        public string $title,
        public array $cells,
        /** Already in words: "+2 more", a size. */
        public ?string $detail = null,
        /** A thumb's address; null for none. */
        public ?string $photo = null,
        /** The screen the row opens. */
        public ?string $href = null,
    ) {}
}
