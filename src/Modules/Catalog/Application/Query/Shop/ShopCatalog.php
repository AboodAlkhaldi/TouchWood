<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * **What a shopper reads** (catalog.md §1.4, §1.5, §1.10): a store's menu, a category's and a
 * brand's pages, a product's page and what it suggests. Anyone may read them — a guest included — so
 * no permission is asked; what a shopper may not see is simply not in the listing (§5.4). The store
 * is the one the storefront's request resolved, and nothing answered holds a code (amendment 5(d)).
 */
final readonly class ShopCatalog
{
    /** Cards on a page unless the screen asks for another number. */
    public const int PAGE = 24;

    public const int PAGE_MAX = 100;

    /** At most as many suggestions in each list as staff may pick (§1.1). */
    public const int SUGGESTIONS_MAX = 20;

    /** Brands a shopper may pick at once in a category's filter. */
    public const int BRANDS_MAX = 50;

    public function __construct(
        private ShopReader $reader,
        private PlatformApi $platform,
    ) {}

    /**
     * The store's menu (§1.5): each category shown by itself once the store lists something in it or
     * below it, in the store's order — or the base store's, until its admins place it (amendment
     * 5(a)). A secondary brand is reached through its own category, which shows here as any other
     * (5(k)).
     *
     * @return list<MenuCategory>
     *
     * @throws InvalidCatalogAttribute
     */
    public function menu(StoreId $store, string $locale): array
    {
        $base = null;

        foreach ($this->platform->allStores() as $candidate) {
            if ($candidate->isBase) {
                $base = $candidate->id;
            }
        }

        $byParent = [];

        foreach ($this->reader->menu($store->value, $base, ShopLocale::of($locale)) as $row) {
            $byParent[$row['parent_id'] ?? ''][] = $row;
        }

        return self::branch($byParent, '');
    }

    /**
     * A category's page (§1.5): everything listed in it or below it, of every brand (amendment 5(k))
     * — or of the brands the shopper picked in the filter, which narrows the page and never takes it
     * away. A category that is off, or lists nothing here, is not in this store: no page.
     *
     * @param  list<string>  $brandIds  the brands the shopper picked in the filter
     *
     * @throws InvalidCatalogAttribute
     */
    public function category(StoreId $store, string $locale, string $slug, array $brandIds = [], ?string $after = null, int $limit = self::PAGE): CategoryPage|Moved|null
    {
        $locale = ShopLocale::of($locale);
        $cursor = Cursor::parse($after);

        if (count($brandIds) > self::BRANDS_MAX) {
            throw new InvalidCatalogAttribute('brands', 'at most '.self::BRANDS_MAX.' brands');
        }

        $owner = $this->reader->slugOwner('category', $locale, self::slug($locale, $slug));
        $category = $owner === null ? null : $this->reader->category($owner['id'], $locale);

        if ($owner === null || $category === null || ! $this->reader->categoryLists($store->value, $locale, $owner['id'])) {
            return null;
        }

        if ($owner['slug'] !== self::slug($locale, $slug)) {
            return new Moved($owner['slug']);
        }

        $brands = array_values(array_unique(array_map(static fn (string $id): string => strtolower($id), $brandIds)));

        return new CategoryPage($owner['id'], $category['name'], $owner['slug'], $this->reader->categoryCards($store->value, $locale, $owner['id'], $brands, $cursor, self::limit($limit, self::PAGE_MAX)));
    }

    /**
     * A brand's page (§1.4, §1.6): everything of it a shopper can order here. An inactive brand has
     * none.
     *
     * @throws InvalidCatalogAttribute
     */
    public function brand(StoreId $store, string $locale, string $slug, ?string $after = null, int $limit = self::PAGE): BrandPage|Moved|null
    {
        $locale = ShopLocale::of($locale);
        $cursor = Cursor::parse($after);
        $owner = $this->reader->slugOwner('brand', $locale, self::slug($locale, $slug));
        $brand = $owner === null ? null : $this->reader->brand($owner['id'], $locale);

        if ($owner === null || $brand === null || ! $brand['is_active']) {
            return null;
        }

        if ($owner['slug'] !== self::slug($locale, $slug)) {
            return new Moved($owner['slug']);
        }

        return new BrandPage($owner['id'], $brand['name'], $owner['slug'], $brand['logo_media_id'], $this->reader->brandCards($store->value, $locale, $owner['id'], $cursor, self::limit($limit, self::PAGE_MAX)));
    }

    /**
     * A product's page (§1.4): available while a shopper can find it here; otherwise the one "Not
     * available now" page, for anything that exists and was ever shown. A slug that never existed —
     * or a product never made ready, which was never shown and is Catalog's alone (§6.1) — has none.
     *
     * @throws InvalidCatalogAttribute
     */
    public function product(StoreId $store, string $locale, string $slug): ProductPage|Moved|null
    {
        $locale = ShopLocale::of($locale);
        $owner = $this->reader->slugOwner('product', $locale, self::slug($locale, $slug));
        $product = $owner === null ? null : $this->reader->product($owner['id'], $locale);

        if ($owner === null || $product === null || $product['stage'] === 'DRAFT' || $product['archived_from'] === 'DRAFT') {
            return null;
        }

        if ($owner['slug'] !== self::slug($locale, $slug)) {
            return new Moved($owner['slug']);
        }

        $available = $this->reader->isListed($store->value, $locale, $owner['id']);
        $photos = [];

        foreach ($this->reader->gallery($owner['id']) as $mediaId) {
            $urls = $this->platform->mediaUrls($mediaId);

            if ($urls !== null && $urls->variants !== []) {
                $photos[] = $urls->variants;
            }
        }

        return new ProductPage(
            $owner['id'],
            (string) $product['name'],
            $owner['slug'],
            $available,
            $product['description'] ?? [],
            $photos,
            $available ? $this->reader->labels($store->value, $locale, $owner['id']) : [],
            $available ? $this->reader->variantsOnSale($store->value, $locale, $owner['id']) : [],
        );
    }

    /**
     * What a product's page suggests (§1.10), only what a shopper can order here: the hand-picked
     * "Related" — or, when staff picked none, the same category's, then the same brand's, a secondary
     * brand's products only on its own products' pages (amendment 5(l)) — and the hand-picked "Goes
     * with".
     *
     * @throws InvalidCatalogAttribute
     */
    public function relations(StoreId $store, string $locale, string $productId, int $limit = self::SUGGESTIONS_MAX): Relations
    {
        $locale = ShopLocale::of($locale);
        $limit = self::limit($limit, self::SUGGESTIONS_MAX);
        $product = $this->reader->product(strtolower($productId), $locale);

        if ($product === null) {
            return new Relations([], []);
        }

        $id = strtolower($productId);
        $picked = $this->reader->picked($id, 'RELATED');

        if ($picked !== []) {
            $mayAlsoLike = array_slice($this->reader->cardsOf($store->value, $locale, $picked), 0, $limit);
        } else {
            $mayAlsoLike = $product['category_id'] === null ? [] : $this->reader->cardsSharing($store->value, $locale, 'category_id', $product['category_id'], $product['brand_id'], [$id], $limit);

            if (count($mayAlsoLike) < $limit) {
                $shown = [$id, ...array_map(static fn (ProductCard $card): string => $card->productId, $mayAlsoLike)];
                array_push($mayAlsoLike, ...$this->reader->cardsSharing($store->value, $locale, 'brand_id', $product['brand_id'], $product['brand_id'], $shown, $limit - count($mayAlsoLike)));
            }
        }

        return new Relations($mayAlsoLike, array_slice($this->reader->cardsOf($store->value, $locale, $this->reader->picked($id, 'GOES_WITH')), 0, $limit));
    }

    /**
     * @param  array<string, list<array{id: string, parent_id: string|null, name: string, slug: string, image_media_id: string|null}>>  $byParent
     * @return list<MenuCategory>
     */
    private static function branch(array $byParent, string $parentId): array
    {
        return array_map(
            static fn (array $row): MenuCategory => new MenuCategory($row['id'], $row['name'], $row['slug'], $row['image_media_id'], self::branch($byParent, $row['id'])),
            $byParent[$parentId] ?? [],
        );
    }

    /** An English slug is kept in small letters (§5.1); an Arabic one as it is. */
    private static function slug(string $locale, string $slug): string
    {
        return $locale === 'en' ? strtolower(trim($slug)) : trim($slug);
    }

    private static function limit(int $limit, int $max): int
    {
        return min(max($limit, 1), $max);
    }
}
