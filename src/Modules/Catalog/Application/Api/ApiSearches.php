<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Api;

use Modules\Catalog\Public\Dto\CategoryPathDto;
use Modules\Catalog\Public\Dto\FoundProductsDto;
use Modules\Catalog\Public\Dto\FoundVariantsDto;
use Modules\Catalog\Public\Dto\WholesaleTermsDto;

/**
 * The searches behind `CatalogApi` for stage 5's store-wide screens (catalog.md amendment 23): pages of
 * sizes and products in Catalog's order with their totals, categories with their paths, and wholesale
 * terms - each a fixed few queries, so Pricing's and Inventory's pages keep their 15 (frontend.md §5).
 * `CatalogApiImpl` hands them the search text read as the products list reads it, and the page's
 * language for the order.
 */
interface ApiSearches
{
    /**
     * @param  list<string>|null  $only
     * @param  list<string>|null  $except
     * @param  'ar'|'en'  $locale
     */
    public function variants(string $storeId, ?string $text, ?array $only, ?array $except, bool $soldWholesale, bool $anyReady, ?string $after, int $limit, string $locale, bool $notArchived = false): FoundVariantsDto;

    /**
     * @param  list<string>|null  $only
     * @param  'ar'|'en'  $locale
     */
    public function products(string $storeId, ?string $text, bool $readySwitchedOn, ?array $only, ?string $after, int $limit, string $locale): FoundProductsDto;

    /**
     * @param  'ar'|'en'  $locale
     */
    public function productsInCategory(string $categoryId, ?string $text, ?string $after, int $limit, string $locale): FoundProductsDto;

    /**
     * @param  list<string>  $ids
     * @return array<string, CategoryPathDto>
     */
    public function categoriesWithPaths(array $ids): array;

    /**
     * @param  list<string>  $variantIds
     * @return array<string, WholesaleTermsDto>
     */
    public function wholesaleOf(string $storeId, array $variantIds): array;
}
