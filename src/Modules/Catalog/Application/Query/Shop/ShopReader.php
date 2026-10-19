<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

use Modules\Catalog\Application\Search\SearchTerms;
use Modules\Platform\Public\Enums\ShopperKind;

/**
 * What a shopper reads (catalog.md §1.4, §1.5, §1.10, §4.5), from the listing (§5.4) and the slugs.
 * Reads never lock a row, and **nothing they answer holds a code** (amendment 5(d)). Every list of
 * cards holds only what a shopper can order now, best-selling first, then newest — or newest only,
 * when the shopper sorts so (P15).
 *
 * **Every read of what is listed answers for one viewer** (§1.13, amendment 14): an individual the
 * products whose own category is on for individuals and that sell retail there, a company those
 * whose category is on for companies, a guest what either sees (P25).
 *
 * **Built for the budget** (P21): a page's head — who holds the slug, its name, its slug in the other
 * language and whether it lists anything, and for a category its filters with their counts — is one
 * read; a page of cards with their labels and their "Last pieces" is one read.
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
     * A category's page head and its filters, in one read (P14, P21): who holds the slug — now or
     * before — their slug now in this language and in the other, the name, whether its pages list
     * anything in this store for this viewer (a category that is off lists nothing), and the brands,
     * the filter attributes' values present and Retail and Wholesale for a company or a guest, each
     * with how many products picking it would show — every other kind of choice the shopper made kept,
     * its own kind's left out. A choice the page no longer offers (a brand not listed here, a value
     * switched off or held by nothing here, a side nothing here is sold on) counts as not made.
     *
     * @return array{id: string, slug: string, other_slug: string|null, name: string, lists: bool, filters: CategoryFilters}|null
     */
    public function categoryHead(string $storeId, string $locale, ShopperKind $viewer, string $slug, ShopFilters $filters): ?array;

    /** Whether the store's lists show ratings — stars and the rating sort (amendment 25); never told, they do. */
    public function ratingsShown(string $storeId): bool;

    /**
     * A brand's page head, in one read: who holds the slug — now or before — their slug now in this
     * language and in the other, and the brand as its page shows it.
     *
     * @return array{id: string, slug: string, other_slug: string|null, name: string, is_active: bool, logo_media_id: string|null, description: array<string, mixed>|null}|null
     */
    public function brandHead(string $locale, string $slug): ?array;

    /**
     * A category's page: what is listed in it or below it for this viewer, of every brand — narrowed
     * by the shopper's filters (amendment 5(k), P14) — in the shopper's sort (P15).
     */
    public function categoryCards(string $storeId, string $locale, ShopperKind $viewer, string $categoryId, ShopFilters $filters, ?Cursor $after, int $limit): CardPage;

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
     * A product as its page needs it, in that language, whatever its stage: its brand — its name and
     * current slug — its warranty, and its slug now in the other language.
     *
     * @return array{stage: string, archived_from: string|null, name: string|null, description: array<string, mixed>|null, brand_id: string, category_id: string|null, brand: ShopLink|null, warranty: ShopWarranty|null, other_slug: string|null}|null
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
     * Each variant's own photos (§1.2), in order.
     *
     * @param  list<string>  $variantIds
     * @return array<string, list<string>> variant id => media ids
     */
    public function variantPhotos(array $variantIds): array;

    /**
     * The labels the store attached, in the list's order.
     *
     * @return list<CardLabel>
     */
    public function labels(string $storeId, string $locale, string $productId): array;

    /**
     * How the product is sold in this store (§1.3), or null when the store never chose it.
     */
    public function sellingTerms(string $storeId, string $productId): ?SellingTerms;

    /**
     * The variants this viewer can order in this store, in the product's order — for an individual,
     * those sold retail only (amendment 14(e)); a company and a guest see both sides (§4.5, §8 #33) —
     * each with how it is sold, its details, weight and size, and whether it is ending soon and can be
     * ordered now (amendment 16(h)). Their photos are read apart (`variantPhotos`).
     *
     * @return list<ShopVariant>
     */
    public function variantsOnSale(string $storeId, string $locale, ShopperKind $viewer, string $productId): array;

    /**
     * The product's choices (§4.5): its variant attributes in its order, each with the values its
     * variants that are not archived hold, in the attribute's order — marked on sale when one of
     * these variants holds it. An individual is never offered a size sold wholesale only here
     * (§1.13, amendment 14(e)): its values are left out unless another size holds them.
     *
     * @param  list<string>  $onSaleVariantIds
     * @return list<ShopChoice>
     */
    public function choices(string $storeId, string $locale, ShopperKind $viewer, string $productId, array $onSaleVariantIds): array;

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
     * by sales rank, then newest — from where the last page ended (P16).
     */
    public function search(string $storeId, string $locale, ShopperKind $viewer, SearchTerms $terms, ?SearchCursor $after, int $limit): SearchResults;

    /**
     * Hand-picked related products of one kind, in their order.
     *
     * @param  'RELATED'|'GOES_WITH'  $kind
     * @return list<string>
     */
    public function picked(string $productId, string $kind): array;
}
