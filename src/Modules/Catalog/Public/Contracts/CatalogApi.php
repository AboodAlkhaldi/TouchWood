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
    /**
     * For Pricing, Inventory, Sales, Shipping: the code, the product, the values, weight and
     * dimensions. **The code is for staff and the modules above — never shown to a shopper**, an
     * order's own pages included (owner, 2026-10-04, amendment 5(d)).
     */
    public function variant(string $variantId): ?VariantDto;

    /**
     * **The one variant carrying the code**, archived or not — every variant has its own (amendment
     * 16(a), replacing 3(e)'s `variantsByCode`) — for Sync (the provider's codes), Pricing and the
     * import's later sections. A code that is not one, or one no variant carries now, finds none.
     */
    public function variantByCode(string $code): ?VariantDto;

    /**
     * For Pricing and Inventory: **the product's variants in its own order** — archived ones only
     * when asked (catalog.md §2.1, amendment 16(e)). An unknown product has none.
     *
     * @return list<string>
     */
    public function variantIdsOf(string $productId, bool $includeArchived = false): array;

    /**
     * For Pricing's category discounts: **the products in the category and in every category below
     * it**, any stage (amendment 16(e)). An unknown category holds none. When a category moves,
     * `CategoryMoved` says so.
     *
     * @return list<string>
     */
    public function productIdsInCategory(string $categoryId): array;

    /**
     * For Pricing's "Needs a Price" and Inventory: **the variants switched on in the store** — Active
     * there (§1.3) — in no particular order (amendment 16(e)).
     *
     * @return list<string>
     */
    public function switchedOnVariantIds(StoreId $store): array;

    /**
     * **Many variants in one read**, keyed by id, an unknown id left out (amendment 16(i)): a few
     * queries however many, so a page of Pricing's or Inventory's keeps its budget.
     *
     * @param  list<string>  $variantIds
     * @return array<string, VariantDto>
     */
    public function variants(array $variantIds): array;

    /**
     * **Many products in one read**, keyed by id, an unknown id left out (amendment 16(i)).
     *
     * @param  list<string>  $productIds
     * @return array<string, ProductDto>
     */
    public function products(array $productIds): array;

    /** For Sales's record of an order, Feedback, Content. */
    public function product(string $productId): ?ProductDto;

    /**
     * For Sales: the variant in that store (§1.3). Null for a variant that does not exist. Whether
     * the store is on is Platform's to say (`PlatformApi::store`); a store that is off still answers
     * here, as it is prepared before it opens (amendment 4(e)).
     */
    public function storeVariant(StoreId $store, string $variantId): ?StoreVariantDto;

    /**
     * The variant a shopper's picked values name (handoff §9.1: the server resolves it, never the
     * browser): the product's variant, not archived, made of exactly these values.
     *
     * @param  list<string>  $valueIds
     */
    public function resolveVariant(string $productId, array $valueIds): ?string;
}
