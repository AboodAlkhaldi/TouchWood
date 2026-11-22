<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

/**
 * **A page of `CatalogApi::searchVariants`** (catalog.md amendment 23(a)): the sizes in Catalog's order -
 * product name in the page's language, then the size's place -, the cursor of the page after it (none
 * on the last), and how many the search finds in all ("Showing 50 of 312").
 */
final readonly class FoundVariantsDto
{
    /**
     * @param  list<FoundVariantDto>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
        public int $total,
    ) {}
}
