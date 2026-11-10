<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

/**
 * **A page of `CatalogApi::sitemapEntries`** (catalog.md amendment 41(b)): categories first, then brands, then
 * products, each by id; the cursor of the page after it, none on the last.
 */
final readonly class SitemapEntriesDto
{
    /**
     * @param  list<SitemapEntryDto>  $entries
     */
    public function __construct(
        public array $entries,
        public ?string $next,
    ) {}
}
