<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

/**
 * **A page of `CatalogApi::searchProducts` or `::productsInCategory`** (catalog.md amendment 23(b), (c)):
 * the products by name in the page's language, the cursor of the page after it (none on the last), and
 * how many are found in all.
 */
final readonly class FoundProductsDto
{
    /**
     * @param  list<FoundProductDto>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
        public int $total,
    ) {}
}
