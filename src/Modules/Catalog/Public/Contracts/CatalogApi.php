<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Catalog\Public\Dto\StoreVariantDto;
use Modules\Catalog\Public\Dto\VariantDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * What other modules may ask Catalog (catalog.md §2.1): ids in, DTOs out. Module to module, so no
 * permission is checked here — the calling use case checks its own. Plain reads: no lock, no
 * transaction.
 */
interface CatalogApi
{
    /** For Pricing, Inventory, Sales, Shipping: the code, the product, the values, weight and dimensions. */
    public function variant(string $variantId): ?VariantDto;

    /**
     * Every variant holding the code — all of one product (amendment 3(e)) — for Sync and the
     * import's later sections. A code that is not one finds nothing.
     *
     * @return list<VariantDto>
     */
    public function variantsByCode(string $code): array;

    /** For Sales's record of an order, Feedback, Content. */
    public function product(string $productId): ?ProductDto;

    /** For Sales: the variant in that store (§1.3). Null for a variant that does not exist. */
    public function storeVariant(StoreId $store, string $variantId): ?StoreVariantDto;

    /**
     * The variant a shopper's picked values name (handoff §9.1: the server resolves it, never the
     * browser): the product's variant, not archived, made of exactly these values.
     *
     * @param  list<string>  $valueIds
     */
    public function resolveVariant(string $productId, array $valueIds): ?string;
}
