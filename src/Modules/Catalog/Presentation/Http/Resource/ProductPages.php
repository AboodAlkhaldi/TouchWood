<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Import\DescriptionText;
use Modules\Catalog\Application\Query\ListProducts\ListProducts;
use Modules\Catalog\Application\Query\ListProducts\ListProductsHandler;
use Modules\Catalog\Application\Query\Products\AttributeChoice;
use Modules\Catalog\Application\Query\Products\CatalogProductReads;
use Modules\Catalog\Application\Query\Products\ProductFilter;
use Modules\Catalog\Application\Query\Products\ProductOptions;
use Modules\Catalog\Application\Query\Products\ProductRow;
use Modules\Catalog\Application\Query\Products\RelatedRow;
use Modules\Catalog\Application\Query\Products\VariantView;
use Modules\Catalog\Application\Query\ViewProduct\ViewProduct;
use Modules\Catalog\Application\Query\ViewProduct\ViewProductHandler;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * The products screens' pages, in the shape the screens want (catalog.md §4.4 S8, S9).
 *
 * It decides nothing: who may read the products and what they may do to one is Catalog's answer -
 * `ListProducts`, `ViewProduct` -, which store a page shows is Platform's `StoreChoices`, as on every
 * store screen (frontend.md §2.2). What happens here is shaping: photos into thumbnails, a page's
 * photos in one read (`mediaUrlsOf`), a category's path into words, a description back into the marks
 * typed (P1), store ids into their codes.
 */
final readonly class ProductPages
{
    public function __construct(
        private Application $app,
        private Thumbnails $thumbnails,
        private StoreChoices $choices,
        private ListProductsHandler $list,
        private ViewProductHandler $view,
        private CatalogProductReads $reads,
    ) {}

    /**
     * The products list: All Stores first, else the store chosen among those the reader covers.
     *
     * @throws Unauthorized when a store is asked for that the reader does not cover
     */
    public function list(?string $search, ?string $stage, ?string $categoryId, ?string $brandId, ?string $storeCode, ?string $storeState, ?string $after): ProductsPage
    {
        $stores = $this->choices->forJobs(CatalogPermissions::PRODUCT_VIEW);
        $chosen = $storeCode === null ? null : self::storeIn($stores, $storeCode);
        $list = $this->list->handle(new ListProducts(new ProductFilter($search, $stage, $categoryId, $brandId, $chosen?->id, $storeState, $after)));
        $codes = self::codes($stores);
        $thumbs = $this->thumbnails->of(array_map(static fn (ProductRow $row): ?string => $row->photoMediaId, $list->products));
        $options = $this->reads->options();
        $last = $list->products === [] ? null : $list->products[count($list->products) - 1]->id;

        return new ProductsPage(
            array_map(fn (ProductRow $row): ProductRowData => $this->row($row, $thumbs, $codes), $list->products),
            $list->more,
            $list->more ? $last : null,
            $search,
            $stage,
            $categoryId,
            $brandId,
            $chosen === null ? null : $storeState,
            $chosen?->code,
            array_map(fn (StoreDto $store): StoreOptionData => new StoreOptionData($store->id, $store->code, $store->name->in($this->locale()), $store->isActive), $stores),
            $list->mayCreate,
            self::brands($options),
            self::categories($options),
        );
    }

    /**
     * One product's page, with its open tab's data; on the Related tab, the ready products a search
     * for one to add found.
     */
    public function product(string $productId, string $tab, ?string $find): ProductPage
    {
        $view = $this->view->handle(new ViewProduct($productId, $tab));
        $core = $view->product;
        $variantPhotos = [];

        foreach ($view->variants ?? [] as $variant) {
            $variantPhotos = [...$variantPhotos, ...$variant->photos];
        }

        $thumbs = $this->thumbnails->of([...$core->gallery, ...$variantPhotos]);
        $photo = static fn (string $mediaId): PhotoData => new PhotoData($mediaId, $thumbs[$mediaId] ?? null, $view->photoStates[$mediaId] ?? 'PENDING');
        $found = null;

        // A search for a product to relate: the ready ones, read as the list reads them - the page is
        // already the reader's to read (ViewProduct).
        if ($view->tab === ViewProduct::RELATED && $find !== null && trim($find) !== '') {
            [$rows] = $this->reads->products(new ProductFilter(search: trim($find), stage: 'READY'), 20);
            $found = array_map(fn (ProductRow $row): ProductRowData => $this->row($row, [], []), array_values(array_filter($rows, static fn (ProductRow $row): bool => $row->id !== $core->id)));
        }

        return new ProductPage(
            new ProductHeadData(
                $core->id, $core->nameAr, $core->nameEn, $core->slugAr, $core->slugEn, $core->stage, $core->archivedFrom,
                $core->brandId, $core->brandNameAr, $core->brandNameEn, $core->categoryId,
                $core->categoryPath === [] ? null : implode(' › ', array_column($core->categoryPath, 'ar')),
                $core->categoryPath === [] ? null : implode(' › ', array_column($core->categoryPath, 'en')),
                $core->warrantyId, $core->attributeSetId, $core->attributeSetNameAr, $core->attributeSetNameEn, $core->setAttributeIds,
                DescriptionText::text($core->descriptionAr), DescriptionText::text($core->descriptionEn),
                $core->hiddenByCategory, $core->hiddenByBrand, $core->codes,
                new ProductCountsData(...$core->counts),
            ),
            $view->tab,
            $view->missing,
            array_map($photo, $core->gallery),
            $view->mayUpdate,
            $view->mayPublish,
            $view->mayArchive,
            $view->mayCorrectCode,
            $view->options === null ? null : self::brands($view->options),
            $view->options === null ? null : self::categories($view->options),
            $view->options === null ? null : array_map(static fn (array $warranty): WarrantyOptionData => new WarrantyOptionData(...$warranty), $view->options->warranties),
            $view->options === null ? null : array_map(static fn (array $set): VariationOptionData => new VariationOptionData(...$set), $view->options->variations),
            $view->variants === null ? null : array_map(static fn (VariantView $variant): VariantData => new VariantData(
                $variant->id, $variant->code, $variant->position, $variant->archived,
                array_map(static fn (array $value): VariantValueData => new VariantValueData(...$value), $variant->values),
                array_map(static fn (array $detail): VariantDetailData => new VariantDetailData(...$detail), $variant->details),
                $variant->weightGrams, $variant->lengthMm, $variant->widthMm, $variant->heightMm,
                array_map($photo, $variant->photos),
            ), $view->variants),
            $view->attributes === null ? null : array_map(static fn (AttributeChoice $attribute): AttributeChoiceData => new AttributeChoiceData(
                $attribute->id, $attribute->nameAr, $attribute->nameEn, $attribute->kind, $attribute->unitAr, $attribute->unitEn,
                $attribute->isColour, $attribute->active,
                array_map(static fn (array $value): ChoiceValueData => new ChoiceValueData(...$value), $attribute->values),
            ), $view->attributes),
            $view->searchWords,
            $view->filterValueIds,
            $view->related === null ? null : array_map(static fn (RelatedRow $row): RelatedData => new RelatedData(
                $row->kind, $row->productId, $row->nameAr, $row->nameEn, $row->codes, $row->stage,
            ), $view->related),
            $found,
        );
    }

    /**
     * @param  array<string, string>  $thumbs
     * @param  array<string, string>  $codes  store id => code
     */
    private function row(ProductRow $row, array $thumbs, array $codes): ProductRowData
    {
        return new ProductRowData(
            $row->id, $row->nameAr, $row->nameEn, $row->codes, $row->stage, $row->brandNameAr, $row->brandNameEn,
            $row->categoryNameAr, $row->categoryNameEn, $thumbs[$row->photoMediaId ?? ''] ?? null, $row->variants,
            // A store the reader is not offered (off, for anyone but a Super Admin) is not named.
            array_values(array_filter(array_map(static fn (string $id): ?string => $codes[strtolower($id)] ?? null, $row->onIn))),
            $row->storeState, $row->storeVariantsOn,
        );
    }

    /**
     * @return list<BrandOptionData>
     */
    private static function brands(ProductOptions $options): array
    {
        return array_map(static fn (array $brand): BrandOptionData => new BrandOptionData(...$brand), $options->brands);
    }

    /**
     * @return list<CategoryOptionData>
     */
    private static function categories(ProductOptions $options): array
    {
        return array_map(static fn (array $category): CategoryOptionData => new CategoryOptionData(
            $category['id'],
            implode(' › ', array_column($category['path'], 'ar')),
            implode(' › ', array_column($category['path'], 'en')),
        ), $options->categories);
    }

    /**
     * @param  list<StoreDto>  $stores
     * @return array<string, string> store id => code
     */
    private static function codes(array $stores): array
    {
        $codes = [];

        foreach ($stores as $store) {
            $codes[strtolower($store->id)] = $store->code;
        }

        return $codes;
    }

    /**
     * The store asked for in the page's filter, among those offered; another, or one that does not
     * exist, is the same refusal (StoreChoices).
     *
     * @param  list<StoreDto>  $stores
     *
     * @throws Unauthorized
     */
    private static function storeIn(array $stores, string $code): StoreDto
    {
        foreach ($stores as $store) {
            if (strtolower($store->code) === strtolower(trim($code))) {
                return $store;
            }
        }

        throw new Unauthorized(CatalogPermissions::PRODUCT_VIEW);
    }

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
