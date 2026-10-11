<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Products;

/**
 * **What the products screens read** (catalog.md §4.4 S8, S9) — each one query, never one per row
 * (frontend.md §5). The queries that call these ask for the reader's job first; these answer what is
 * there.
 */
interface CatalogProductReads
{
    /**
     * A page of products, newest first, by keyset.
     *
     * @return array{0: list<ProductRow>, 1: bool} the rows, and whether another page follows
     */
    public function products(ProductFilter $filter, int $perPage): array;

    /** The product above its tabs, or null when there is none. */
    public function core(string $productId): ?ProductCore;

    /**
     * Many products as `core()` reads one, in one query - a store file's products (§4.4 S10, P13).
     *
     * @param  list<string>  $productIds
     * @return array<string, ProductCore> by product id; one not found, or not a ULID, is left out
     */
    public function cores(array $productIds): array;

    /**
     * Its variants, archived ones too, in their order.
     *
     * @return list<VariantView>
     */
    public function variants(string $productId): array;

    /**
     * Every attribute, inactive ones too, with its values in order — the forms choose from them.
     *
     * @return list<AttributeChoice>
     */
    public function attributeChoices(): array;

    /** What its Details can point at. */
    public function options(): ProductOptions;

    /**
     * Its search words in their order and the filter values it carries.
     *
     * @return array{words: list<string>, valueIds: list<string>}
     */
    public function searchAndFilters(string $productId): array;

    /**
     * The products it relates to, "You May Also Like" then "Goes With", each in its order.
     *
     * @return list<RelatedRow>
     */
    public function related(string $productId): array;

    /**
     * What each store that chose it does with it, in every store - the caller keeps the ones its
     * reader covers.
     *
     * @return array<string, StoreListingRow> store id => its row
     */
    public function storeRows(string $productId): array;

    /**
     * The labels a store may put on a product - the active ones - and those the product already
     * carries in some store whatever their state (a label deactivated since stays, §1.8), in order.
     *
     * @return list<LabelChoice>
     */
    public function labelChoices(string $productId): array;
}
