<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

use Modules\Catalog\Application\Search\SearchTerms;
use Modules\Platform\Public\Enums\ShopperKind;

/**
 * What a shopper reads (catalog.md §1.4, §1.5, §1.10), from the listing (§5.4) and the slugs. Reads
 * never lock a row, and **nothing they answer holds a code** (amendment 5(d)). Every list of cards
 * holds only what a shopper can order now, best-selling first, then newest.
 *
 * **Every read of what is listed answers for one viewer** (§1.13, amendment 14): an individual the
 * products whose own category is on for individuals and that sell retail there, a company those
 * whose category is on for companies, a guest what either sees (P25).
 */
interface ShopReader
{
    /**
     * The categories in a store's menu (§1.5, amendment 5(a), (k)): active, with something listed in
     * them or below them **for this viewer** — of any brand: a secondary brand is reached through its
     * own category — in the store's own order among their siblings, or the base store's until the
     * store's admins place them, then by name.
     *
     * @return list<array{id: string, parent_id: string|null, name: string, slug: string, image_media_id: string|null}>
     */
    public function menu(string $storeId, ?string $baseStoreId, string $locale, ShopperKind $viewer): array;

    /**
     * Who holds a slug of this kind in this language — now or before — and their slug there now.
     *
     * @param  'product'|'category'|'brand'  $kind
     * @return array{id: string, slug: string}|null
     */
    public function slugOwner(string $kind, string $locale, string $slug): ?array;

    /**
     * @return array{name: string}|null
     */
    public function category(string $categoryId, string $locale): ?array;

    /**
     * Whether a category's pages list anything in this store for this viewer — a category that is
     * off lists nothing.
     */
    public function categoryLists(string $storeId, string $locale, ShopperKind $viewer, string $categoryId): bool;

    /**
     * @return array{name: string, is_active: bool, logo_media_id: string|null}|null
     */
    public function brand(string $brandId, string $locale): ?array;

    /**
     * A category's page: what is listed in it or below it for this viewer, of every brand — or of these
     * brands, when the shopper picked some (amendment 5(k)).
     *
     * @param  list<string>  $brandIds
     */
    public function categoryCards(string $storeId, string $locale, ShopperKind $viewer, string $categoryId, array $brandIds, ?Cursor $after, int $limit): CardPage;

    /**
     * A brand's page: everything of it this viewer can order here, a product left in an inactive
     * category included (§1.4).
     */
    public function brandCards(string $storeId, string $locale, ShopperKind $viewer, string $brandId, ?Cursor $after, int $limit): CardPage;

    /**
     * These products' cards, in the order given, those this viewer can order here only.
     *
     * @param  list<string>  $productIds
     * @return list<ProductCard>
     */
    public function cardsOf(string $storeId, string $locale, ShopperKind $viewer, array $productIds): array;

    /**
     * Cards sharing a category or a brand with a product, best-selling first, leaving some out: of
     * brands shown in default listings, or of the product's own brand — a secondary brand's products
     * are suggested on its own products' pages only (amendment 5(l)) — those this viewer sees only.
     *
     * @param  'category_id'|'brand_id'  $column
     * @param  list<string>  $except
     * @return list<ProductCard>
     */
    public function cardsSharing(string $storeId, string $locale, ShopperKind $viewer, string $column, string $value, string $brandId, array $except, int $limit): array;

    /**
     * A product as its page needs it, in that language, whatever its stage.
     *
     * @return array{stage: string, archived_from: string|null, name: string|null, description: array<string, mixed>|null, brand_id: string, category_id: string|null}|null
     */
    public function product(string $productId, string $locale): ?array;

    /**
     * Whether this viewer can find the product in this store now — listed, or left and so reachable.
     */
    public function isListed(string $storeId, string $locale, ShopperKind $viewer, string $productId): bool;

    /**
     * @return list<string> the gallery's media ids, in order
     */
    public function gallery(string $productId): array;

    /**
     * The labels the store attached, in the list's order.
     *
     * @return list<CardLabel>
     */
    public function labels(string $storeId, string $locale, string $productId): array;

    /**
     * The variants this viewer can order in this store, in the product's order — for an individual,
     * those sold retail only (amendment 14(e)); a company and a guest see both sides (§4.5, §8 #33).
     *
     * @return list<ShopVariant>
     */
    public function variantsOnSale(string $storeId, string $locale, ShopperKind $viewer, string $productId): array;

    /**
     * The shared word pairs one of whose sides holds any of these words (§1.11), as stored.
     *
     * @param  list<string>  $words  normalised
     * @return list<array{string, string}>
     */
    public function wordPairs(array $words): array;

    /**
     * The search (§1.11, amendment 5(c)–(g), (k)): what this viewer can order here, of a brand shown
     * in default listings, whose names, search words (or a pair of them) or categories' names hold
     * every word typed, ranked exact, prefix, nearest, a search word or pair, a category's name; ties
     * by sales rank, then newest.
     */
    public function search(string $storeId, string $locale, ShopperKind $viewer, SearchTerms $terms, int $limit): SearchResults;

    /**
     * Hand-picked related products of one kind, in their order.
     *
     * @param  'RELATED'|'GOES_WITH'  $kind
     * @return list<string>
     */
    public function picked(string $productId, string $kind): array;
}
