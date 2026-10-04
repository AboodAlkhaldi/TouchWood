<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * What a shopper reads (catalog.md §1.4, §1.5, §1.10), from the listing (§5.4) and the slugs. Reads
 * never lock a row, and **nothing they answer holds a code** (amendment 5(d)). Every list of cards
 * holds only what a shopper can order now, best-selling first, then newest.
 */
interface ShopReader
{
    /**
     * The categories in a store's menu (§1.5, amendment 5(a), (h)): active, with something listed in
     * them or below them — of the brand asked for, or else of a brand shown in default listings — in
     * the store's own order among their siblings, or the base store's until the store's admins place
     * them, then by name.
     *
     * @return list<array{id: string, parent_id: string|null, name: string, slug: string, image_media_id: string|null}>
     */
    public function menu(string $storeId, ?string $baseStoreId, string $locale, ?string $brandId): array;

    /**
     * Who holds a slug of this kind in this language — now or before — and their slug there now.
     *
     * @param  'product'|'category'|'brand'  $kind
     * @return array{id: string, slug: string}|null
     */
    public function slugOwner(string $kind, string $locale, string $slug): ?array;

    /**
     * @return array{name: string, is_active: bool}|null
     */
    public function category(string $categoryId, string $locale): ?array;

    /**
     * @return array{name: string, is_active: bool, logo_media_id: string|null}|null
     */
    public function brand(string $brandId, string $locale): ?array;

    /**
     * A category's page: what is listed in it or below it — of these brands when the shopper picked
     * some, or else of brands shown in default listings (handoff §9.4, amendment 5(h)).
     *
     * @param  list<string>  $brandIds
     */
    public function categoryCards(string $storeId, string $locale, string $categoryId, array $brandIds, ?Cursor $after, int $limit): CardPage;

    /**
     * A brand's page: everything of it a shopper can order here, a product left in an inactive
     * category included (§1.4).
     */
    public function brandCards(string $storeId, string $locale, string $brandId, ?Cursor $after, int $limit): CardPage;

    /**
     * These products' cards, in the order given, those a shopper can order here only.
     *
     * @param  list<string>  $productIds
     * @return list<ProductCard>
     */
    public function cardsOf(string $storeId, string $locale, array $productIds): array;

    /**
     * Cards sharing a category or a brand with a product, best-selling first, leaving some out.
     *
     * @param  'category_id'|'brand_id'  $column
     * @param  list<string>  $except
     * @return list<ProductCard>
     */
    public function cardsSharing(string $storeId, string $locale, string $column, string $value, array $except, int $limit): array;

    /**
     * A product as its page needs it, in that language, whatever its stage.
     *
     * @return array{stage: string, archived_from: string|null, name: string|null, description: array<string, mixed>|null, brand_id: string, category_id: string|null}|null
     */
    public function product(string $productId, string $locale): ?array;

    /** Whether a shopper can find the product in this store now — listed, or left and so reachable. */
    public function isListed(string $storeId, string $locale, string $productId): bool;

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
     * The variants a shopper can order in this store, in the product's order.
     *
     * @return list<ShopVariant>
     */
    public function variantsOnSale(string $storeId, string $locale, string $productId): array;

    /**
     * Hand-picked related products of one kind, in their order.
     *
     * @param  'RELATED'|'GOES_WITH'  $kind
     * @return list<string>
     */
    public function picked(string $productId, string $kind): array;
}
