<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Import\DescriptionText;
use Modules\Catalog\Application\Query\ListAttributes\ListAttributes;
use Modules\Catalog\Application\Query\ListAttributes\ListAttributesHandler;
use Modules\Catalog\Application\Query\ListBrands\ListBrands;
use Modules\Catalog\Application\Query\ListBrands\ListBrandsHandler;
use Modules\Catalog\Application\Query\ListCategories\ListCategories;
use Modules\Catalog\Application\Query\ListCategories\ListCategoriesHandler;
use Modules\Catalog\Application\Query\ListLabels\ListLabels;
use Modules\Catalog\Application\Query\ListLabels\ListLabelsHandler;
use Modules\Catalog\Application\Query\Lists\AttributeRow;
use Modules\Catalog\Application\Query\Lists\BrandRow;
use Modules\Catalog\Application\Query\Lists\CategoryRow;
use Modules\Catalog\Application\Query\Lists\LabelRow;
use Modules\Catalog\Application\Query\Lists\NoResultSearchRow;
use Modules\Catalog\Application\Query\Lists\ReachedProductRow;
use Modules\Catalog\Application\Query\Lists\ValueRow;
use Modules\Catalog\Application\Query\Lists\WarrantyRow;
use Modules\Catalog\Application\Query\Lists\WordPairRow;
use Modules\Catalog\Application\Query\ListSearchesWithNoResults\ListSearchesWithNoResults;
use Modules\Catalog\Application\Query\ListSearchesWithNoResults\ListSearchesWithNoResultsHandler;
use Modules\Catalog\Application\Query\ListWarranties\ListWarranties;
use Modules\Catalog\Application\Query\ListWarranties\ListWarrantiesHandler;
use Modules\Catalog\Application\Query\ListWordPairs\ListWordPairs;
use Modules\Catalog\Application\Query\ListWordPairs\ListWordPairsHandler;
use Modules\Catalog\Application\Query\ProductsReached\ProductsReached;
use Modules\Catalog\Application\Query\ProductsReached\ProductsReachedHandler;
use Modules\Catalog\Application\Query\ViewAttribute\ViewAttribute;
use Modules\Catalog\Application\Query\ViewAttribute\ViewAttributeHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * The shared lists' pages, in the shape the screens want (catalog.md §4.4 S1–S7).
 *
 * It decides nothing: **who may read and change each list is Catalog's answer** — each list's query,
 * asking for the list's job — and which store a page shows is Platform's `StoreChoices`, as on every
 * store screen (frontend.md §2.2). What happens here is shaping: a photo's id into its thumbnail, the
 * pictures of a whole page in **one** read (`mediaUrlsOf`, catalog.md §2.4), a description written
 * back as the plain text its form edits (P1), and the products under a category added up.
 */
final readonly class ListPages
{
    public function __construct(
        private Application $app,
        private PlatformApi $platform,
        private Thumbnails $thumbnails,
        private StoreChoices $choices,
        private ListBrandsHandler $brands,
        private ListCategoriesHandler $categories,
        private ListAttributesHandler $attributes,
        private ViewAttributeHandler $attribute,
        private ListLabelsHandler $labels,
        private ListWarrantiesHandler $warranties,
        private ListWordPairsHandler $pairs,
        private ListSearchesWithNoResultsHandler $searches,
        private ProductsReachedHandler $reached,
    ) {}

    /**
     * @param  string|null  $reach  a brand whose deactivation is being prepared
     */
    public function brands(?string $reach): BrandsPage
    {
        $list = $this->brands->handle(new ListBrands);
        $logos = $this->thumbnails->of(array_map(static fn (BrandRow $brand): ?string => $brand->logoMediaId, $list->brands));

        return new BrandsPage(
            array_map(static fn (BrandRow $brand): BrandData => new BrandData(
                $brand->id, $brand->number, $brand->nameAr, $brand->nameEn, $brand->slugAr, $brand->slugEn,
                $brand->agencyType, $brand->showInDefaultListings, $brand->isDefault, $brand->active, $brand->position,
                $brand->originCountry, $brand->logoMediaId, $logos[$brand->logoMediaId ?? ''] ?? null,
                DescriptionText::text($brand->descriptionAr), DescriptionText::text($brand->descriptionEn), $brand->products,
            ), $list->brands),
            $list->mayChange,
            $reach === null ? null : $this->reached(ProductsReached::BRAND, $reach),
            $reach === null ? null : strtolower($reach),
            $list->mayChange ? BrandCountries::in($this->locale()) : [],
        );
    }

    /**
     * The tree, and the chosen store's menu order: the stores where the reader orders the menu, a
     * Super Admin's off ones too (StoreChoices); none asked for, the first that is on. Someone who
     * orders no store's menu sees the base store's order, read only.
     *
     * @throws Unauthorized when a store is asked for that the reader may not choose here
     */
    public function categories(?string $storeCode, ?string $reach): CategoriesPage
    {
        // The stores offered, read once: the one shown is chosen among them, as StoreChoices::chosen()
        // chooses - the one asked for, else the first that is on - without reading them again.
        $offered = $this->choices->forJobs(CatalogPermissions::CATEGORY_RANK);
        $chosen = $storeCode === null ? self::firstOn($offered) : self::storeIn($offered, $storeCode, CatalogPermissions::CATEGORY_RANK);
        $list = $this->categories->handle(new ListCategories($chosen?->id));
        $images = $this->thumbnails->of(array_map(static fn (CategoryRow $category): ?string => $category->imageMediaId, $list->categories));
        $below = self::productsBelow($list->categories);

        return new CategoriesPage(
            array_map(static fn (CategoryRow $category): CategoryData => new CategoryData(
                $category->id, $category->parentId, $category->nameAr, $category->nameEn, $category->slugAr, $category->slugEn,
                $category->active, $category->deactivatedWithParent, $category->imageMediaId,
                $images[$category->imageMediaId ?? ''] ?? null,
                $category->products, $below[$category->id] ?? $category->products,
                $category->storeRank, $category->baseRank,
            ), $list->categories),
            $chosen?->code,
            $chosen?->name->in($this->locale()),
            $this->stores($offered),
            $list->mayManage,
            $list->mayRank,
            $reach === null ? null : $this->reached(ProductsReached::CATEGORY, $reach),
            $reach === null ? null : strtolower($reach),
        );
    }

    public function attributes(): AttributesPage
    {
        $list = $this->attributes->handle(new ListAttributes);

        return new AttributesPage(array_map(self::attributeData(...), $list->attributes), $list->mayChange);
    }

    public function attribute(string $attributeId): AttributePage
    {
        $view = $this->attribute->handle(new ViewAttribute($attributeId));

        return new AttributePage(
            self::attributeData($view->attribute),
            array_map(static fn (ValueRow $value): ValueData => new ValueData(
                $value->id, $value->nameAr, $value->nameEn, $value->swatch, $value->active, $value->position, $value->inUse,
            ), $view->values),
            $view->mayChange,
        );
    }

    public function labels(): LabelsPage
    {
        $list = $this->labels->handle(new ListLabels);

        return new LabelsPage(array_map(static fn (LabelRow $label): LabelData => new LabelData(
            $label->id, $label->nameAr, $label->nameEn, $label->tone, $label->active, $label->position, $label->products,
        ), $list->labels), $list->mayChange);
    }

    public function warranties(): WarrantiesPage
    {
        $list = $this->warranties->handle(new ListWarranties);

        return new WarrantiesPage(array_map(static fn (WarrantyRow $warranty): WarrantyData => new WarrantyData(
            $warranty->id, $warranty->nameAr, $warranty->nameEn, $warranty->periodMonths,
            DescriptionText::text($warranty->termsAr), DescriptionText::text($warranty->termsEn),
            $warranty->active, $warranty->products,
        ), $list->warranties), $list->mayChange);
    }

    /**
     * The pairs, and — for a reader who may read them, the job with All stores (§3) — the searches
     * that found nothing, every store's or the one chosen in the page's filter.
     */
    public function searchWords(?string $storeCode, int $page): SearchWordsPage
    {
        $list = $this->pairs->handle(new ListWordPairs);
        $stores = $this->choices->forJobs(CatalogPermissions::SEARCH_WORD_MANAGE);
        $searches = null;
        $more = false;
        $chosen = null;

        if ($list->mayChange) {
            $chosen = $storeCode === null ? null : self::storeIn($stores, $storeCode, CatalogPermissions::SEARCH_WORD_MANAGE);
            $found = $this->searches->handle(new ListSearchesWithNoResults($chosen?->id, $page));
            $byId = [];

            foreach ($this->platform->allStores() as $store) {
                $byId[$store->id] = $store;
            }

            $searches = array_map(fn (NoResultSearchRow $search): NoResultSearchData => new NoResultSearchData(
                $search->query,
                ($byId[$search->storeId] ?? null)?->name->in($this->locale()) ?? $search->storeId,
                $byId[$search->storeId]->code ?? $search->storeId,
                $search->locale,
                $search->times,
                $search->lastSearchedAt,
            ), $found->searches);
            $more = $found->more;
            $page = $found->page;
        }

        return new SearchWordsPage(
            array_map(static fn (WordPairRow $pair): WordPairData => new WordPairData($pair->id, $pair->wordA, $pair->wordB), $list->pairs),
            $list->mayChange,
            $searches,
            $page,
            $more,
            $chosen?->code,
            $this->stores($stores),
            $chosen?->timezone,
        );
    }

    /**
     * @return list<ReachedProductData>
     */
    private function reached(string $kind, string $id): array
    {
        return array_map(static fn (ReachedProductRow $product): ReachedProductData => new ReachedProductData(
            $product->id, $product->nameAr, $product->nameEn, $product->stage, $product->categoryId,
        ), $this->reached->handle(new ProductsReached($kind, $id)));
    }

    private static function attributeData(AttributeRow $attribute): AttributeData
    {
        return new AttributeData(
            $attribute->id, $attribute->nameAr, $attribute->nameEn, $attribute->kind, $attribute->unitAr, $attribute->unitEn,
            $attribute->isColour, $attribute->active, $attribute->position, $attribute->values, $attribute->kindLocked,
            $attribute->inProducts, $attribute->inUse,
        );
    }

    /**
     * Each category's products, in it and under it: its own, added up the tree.
     *
     * @param  list<CategoryRow>  $categories
     * @return array<string, int>
     */
    private static function productsBelow(array $categories): array
    {
        $children = [];
        $own = [];

        foreach ($categories as $category) {
            $own[$category->id] = $category->products;
            $children[$category->parentId ?? ''][] = $category->id;
        }

        $total = [];
        $count = static function (string $id) use (&$count, &$total, $children, $own): int {
            if (isset($total[$id])) {
                return $total[$id];
            }

            $sum = $own[$id] ?? 0;
            $total[$id] = $sum; // A tree has no loop; this only stops one being followed for ever.

            foreach ($children[$id] ?? [] as $child) {
                $sum += $count($child);
            }

            return $total[$id] = $sum;
        };

        foreach (array_keys($own) as $id) {
            $count($id);
        }

        return $total;
    }

    /**
     * The store asked for in the page's filter, among those offered; another, or one that does not
     * exist, is the same refusal, so a filter tells nobody which stores there are (StoreChoices).
     *
     * @param  list<StoreDto>  $stores
     *
     * @throws Unauthorized
     */
    private static function storeIn(array $stores, string $code, string $permission): StoreDto
    {
        foreach ($stores as $store) {
            if (strtolower($store->code) === strtolower(trim($code))) {
                return $store;
            }
        }

        throw new Unauthorized($permission);
    }

    /**
     * The first store that is on: nobody lands in an off store without choosing it (StoreChoices).
     *
     * @param  list<StoreDto>  $stores
     */
    private static function firstOn(array $stores): ?StoreDto
    {
        foreach ($stores as $store) {
            if ($store->isActive) {
                return $store;
            }
        }

        return null;
    }

    /**
     * @param  list<StoreDto>  $stores
     * @return list<StoreOptionData>
     */
    private function stores(array $stores): array
    {
        return array_map(fn (StoreDto $store): StoreOptionData => new StoreOptionData($store->id, $store->code, $store->name->in($this->locale()), $store->isActive), $stores);
    }

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
