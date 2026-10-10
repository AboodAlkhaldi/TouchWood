<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Api;

use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Catalog\Public\Dto\VariantDto;

/**
 * The reads behind `CatalogApi` that answer many rows at once (catalog.md §2.1, amendment 16(e),
 * (i)): each a fixed number of queries however many ids it is given, so Pricing's and Inventory's
 * screens keep the 15-query budget (frontend.md §5). An id that is not one, or names nothing, is
 * left out.
 */
interface ApiReads
{
    /**
     * @return list<string> the product's variants in its own order, archived ones when asked
     */
    public function variantIdsOf(string $productId, bool $includeArchived): array;

    /**
     * @return list<string> the variants switched on in the store (§1.3)
     */
    public function switchedOnVariantIds(string $storeId): array;

    /**
     * @param  list<string>  $variantIds
     * @return array<string, VariantDto> keyed by id
     */
    public function variants(array $variantIds): array;

    /**
     * @param  list<string>  $productIds
     * @return array<string, ProductDto> keyed by id
     */
    public function products(array $productIds): array;
}
