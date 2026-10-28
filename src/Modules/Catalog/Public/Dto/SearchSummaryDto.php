<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

/**
 * **A page of `CatalogApi::searchSummary`** (catalog.md amendment 37): the phrases most searched first, and
 * the cursor of the page after it (none on the last).
 */
final readonly class SearchSummaryDto
{
    /**
     * @param  list<SearchSummaryRowDto>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
    ) {}
}
