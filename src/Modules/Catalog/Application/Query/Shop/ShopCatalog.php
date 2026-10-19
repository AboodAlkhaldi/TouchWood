<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\ShopperKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * **What a shopper reads** (catalog.md §1.4, §1.5, §1.10, §4.5): a store's menu, a category's and a
 * brand's pages, a product's page and what it suggests. Anyone may read them — a guest included — so
 * no permission is asked; what a shopper may not see is simply not in the listing (§5.4). The store
 * is the one the storefront's request resolved, and nothing answered holds a code (amendment 5(d)).
 *
 * **Every read answers for one viewer** (§1.13, amendment 14): the page asks Platform's
 * `ShopperType` who is looking and passes it in — an individual sees the categories switched on for
 * individuals and the retail side only, a company the categories switched on for companies, a guest
 * (a staff view too) what either sees (P25). A product this viewer does not see has the "Not
 * available now" page (P18); a category that lists nothing for them has no page.
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
    public function menu(StoreId $store, string $locale, ShopperKind $viewer): array
    {
        $byParent = [];

        foreach ($this->reader->menu($store->value, $this->baseStore(), ShopLocale::of($locale), $viewer) as $row) {
            $byParent[$row['parent_id'] ?? ''][] = $row;
        }

        return self::branch($byParent, '');
    }

    /** The base store, whose menu order a store follows until its admins place it (amendment 5(a)). */
    public function baseStore(): ?string
    {
        foreach ($this->platform->allStores() as $candidate) {
            if ($candidate->isBase) {
                return $candidate->id;
            }
        }

        return null;
    }

    /**
     * A category's page (§1.5, §4.5): everything listed in it or below it, of every brand (amendment
     * 5(k)) — narrowed by the shopper's filters, which never take the page away (P14) — in their sort
     * (P15), with the filters' counts. A category that is off, or lists nothing here, is not in this
     * store: no page. Its head is one read, its cards one, its filters one (P21).
     *
     * @throws InvalidCatalogAttribute
     */
    public function category(StoreId $store, string $locale, ShopperKind $viewer, string $slug, ?ShopFilters $filters = null, ?string $after = null, int $limit = self::PAGE): CategoryPage|Moved|null
    {
        $locale = ShopLocale::of($locale);
        $cursor = Cursor::parse($after);
        $filters = ($filters ?? ShopFilters::none())->forViewer($viewer);
        $head = $this->reader->categoryHead($store->value, $locale, $viewer, self::slug($locale, $slug), $filters);

        if ($head === null || ! $head['lists']) {
            return null;
        }

        if ($head['slug'] !== self::slug($locale, $slug)) {
            return new Moved($head['slug']);
        }

        // The cards by the choices the page still offers, as its counts were (P14) — the rating sort
        // only where the store's lists show ratings, best-selling otherwise (amendment 25).
        $chosen = $filters->narrowedTo($head['filters']);
        $ratingSort = $this->reader->ratingsShown($store->value);

        if (! $ratingSort && $chosen->sort === ShopFilters::RATING) {
            $chosen = $chosen->withSort(ShopFilters::BEST_SELLING);
        }

        return new CategoryPage(
            $head['id'],
            $head['name'],
            $head['slug'],
            $this->reader->categoryCards($store->value, $locale, $viewer, $head['id'], $chosen, $cursor, self::limit($limit, self::PAGE_MAX)),
            $head['other_slug'],
            $head['filters'],
            $chosen,
            $ratingSort,
        );
    }

    /**
     * A brand's page (§1.4, §1.6, §4.5): everything of it a shopper can order here. An inactive brand
     * has none; an active one always has its page, a secondary one too (amendment 5(m)).
     *
     * @throws InvalidCatalogAttribute
     */
    public function brand(StoreId $store, string $locale, ShopperKind $viewer, string $slug, ?string $after = null, int $limit = self::PAGE): BrandPage|Moved|null
    {
        $locale = ShopLocale::of($locale);
        $cursor = Cursor::parse($after);
        $head = $this->reader->brandHead($locale, self::slug($locale, $slug));

        if ($head === null || ! $head['is_active']) {
            return null;
        }

        if ($head['slug'] !== self::slug($locale, $slug)) {
            return new Moved($head['slug']);
        }

        return new BrandPage(
            $head['id'],
            $head['name'],
            $head['slug'],
            $head['logo_media_id'],
            $this->reader->brandCards($store->value, $locale, $viewer, $head['id'], $cursor, self::limit($limit, self::PAGE_MAX)),
            $head['other_slug'],
            $head['description'] ?? [],
        );
    }

    /**
     * Who holds a product's slug in this language — now or before — and their slug there now: one
     * read, before the page itself is read or taken from the cache (P21).
     *
     * @return array{id: string, slug: string, moved: bool}|null moved: the slug asked for is an old one
     *
     * @throws InvalidCatalogAttribute
     */
    public function productOwner(string $locale, string $slug): ?array
    {
        $locale = ShopLocale::of($locale);
        $owner = $this->reader->slugOwner('product', $locale, self::slug($locale, $slug));

        return $owner === null ? null : [...$owner, 'moved' => $owner['slug'] !== self::slug($locale, $slug)];
    }

    /**
     * A product's page (§1.4, §4.5): available while a shopper can find it here; otherwise the one
     * "Not available now" page, for anything that exists and was ever shown. A slug that never
     * existed — or a product never made ready, which was never shown and is Catalog's alone (§6.1) —
     * has none.
     *
     * @throws InvalidCatalogAttribute
     */
    public function product(StoreId $store, string $locale, ShopperKind $viewer, string $slug): ProductPage|Moved|null
    {
        $owner = $this->productOwner($locale, $slug);

        if ($owner === null) {
            return null;
        }

        $page = $this->productPage($store, $locale, $viewer, $owner['id'], $owner['slug']);

        return $page !== null && $owner['moved'] ? new Moved($owner['slug']) : $page;
    }

    /**
     * The page of a product whose slug was already read — what the shop keeps in its cache (P21): its
     * category (the shop draws the path to it from this viewer's menu), the brand, the labels, the
     * gallery and each variant's own photos (one
     * Platform read for all of them, §2.4), the choices, how it is sold here for this viewer (P17)
     * and its warranty.
     *
     * @throws InvalidCatalogAttribute
     */
    public function productPage(StoreId $store, string $locale, ShopperKind $viewer, string $productId, string $slug): ?ProductPage
    {
        $locale = ShopLocale::of($locale);
        $product = $this->reader->product($productId, $locale);

        if ($product === null || $product['stage'] === 'DRAFT' || $product['archived_from'] === 'DRAFT') {
            return null;
        }

        $available = $this->reader->isListed($store->value, $locale, $viewer, $productId);
        $variants = $available ? $this->reader->variantsOnSale($store->value, $locale, $viewer, $productId) : [];
        $variantIds = array_map(static fn (ShopVariant $variant): string => $variant->variantId, $variants);
        $gallery = $this->reader->gallery($productId);
        $own = $variantIds === [] ? [] : $this->reader->variantPhotos($variantIds);
        $urls = $this->photos([...$gallery, ...array_merge(...array_values($own))]);
        $photosOf = static fn (array $mediaIds): array => array_values(array_filter(array_map(static fn (string $id): ?array => $urls[$id] ?? null, $mediaIds)));
        $terms = $available ? $this->reader->sellingTerms($store->value, $productId) : null;

        return new ProductPage(
            $productId,
            (string) $product['name'],
            $slug,
            $available,
            $product['description'] ?? [],
            $photosOf($gallery),
            $available ? $this->reader->labels($store->value, $locale, $productId) : [],
            array_map(static fn (ShopVariant $variant): ShopVariant => new ShopVariant(
                $variant->variantId,
                $variant->values,
                $variant->sellsRetail,
                $variant->sellsWholesale,
                $variant->lastPieces,
                $photosOf($own[$variant->variantId] ?? []),
                $variant->details,
                $variant->weightGrams,
                $variant->lengthMm,
                $variant->widthMm,
                $variant->heightMm,
            ), $variants),
            $product['other_slug'],
            $product['brand'],
            $product['category_id'],
            $available ? $this->reader->choices($store->value, $locale, $viewer, $productId, $variantIds) : [],
            $available ? $product['warranty'] : null,
            // An individual is told the retail side only (amendment 14(e), P17).
            $terms !== null && $viewer === ShopperKind::Individual ? new SellingTerms($terms->retailMinimum, $terms->retailMaximum, null, null) : $terms,
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
    public function relations(StoreId $store, string $locale, ShopperKind $viewer, string $productId, int $limit = self::SUGGESTIONS_MAX): Relations
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
            $mayAlsoLike = array_slice($this->reader->cardsOf($store->value, $locale, $viewer, $picked), 0, $limit);
        } else {
            $mayAlsoLike = $product['category_id'] === null ? [] : $this->reader->cardsSharing($store->value, $locale, $viewer, 'category_id', $product['category_id'], $product['brand_id'], [$id], $limit);

            if (count($mayAlsoLike) < $limit) {
                $shown = [$id, ...array_map(static fn (ProductCard $card): string => $card->productId, $mayAlsoLike)];
                array_push($mayAlsoLike, ...$this->reader->cardsSharing($store->value, $locale, $viewer, 'brand_id', $product['brand_id'], $product['brand_id'], $shown, $limit - count($mayAlsoLike)));
            }
        }

        return new Relations($mayAlsoLike, array_slice($this->reader->cardsOf($store->value, $locale, $viewer, $this->reader->picked($id, 'GOES_WITH')), 0, $limit));
    }

    /**
     * The ready photos among these, in one Platform read (§2.4; owner, 2026-10-07, #10).
     *
     * @param  list<string>  $mediaIds
     * @return array<string, array<string, array<string, string>>> media id => size slug => format => CDN URL
     */
    private function photos(array $mediaIds): array
    {
        $urls = [];

        foreach ($mediaIds === [] ? [] : $this->platform->mediaUrlsOf(array_values(array_unique($mediaIds))) as $id => $media) {
            if ($media->variants !== []) {
                $urls[$id] = $media->variants;
            }
        }

        return $urls;
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
