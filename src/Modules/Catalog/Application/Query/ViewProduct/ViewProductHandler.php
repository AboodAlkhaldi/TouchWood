<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewProduct;

use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Query\Products\CatalogProductReads;
use Modules\Catalog\Application\Query\Products\ProductCore;
use Modules\Catalog\Application\Query\Products\ProductReaders;
use Modules\Catalog\Application\Query\Products\StoreListingRow;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\MediaVariantsStatus;
use Shared\Application\Unauthorized;

/**
 * **One product's page** (catalog.md §4.4 S9): for whoever reads products in some store, as the list
 * is. What the reader may do is asked of the authorizer as the handlers ask it (`ProductReaders`),
 * only for what the product's stage offers - making ready a draft, correcting a code of a ready one.
 *
 * What it lacks to be made ready follows `Readiness::missing()`'s rules from the page's own reads -
 * the English name and address, both descriptions, a category that may hold it, a variant not
 * archived, a ready photo - so the page reads nothing per rule (tests/Modules/Catalog/Integration/
 * CatalogProductReadsTest holds the two to the same answers).
 */
final readonly class ViewProductHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_VIEW;

    /** The jobs a product's Stores tab draws its controls for (catalog.md §4.4 S9 "Stores"). */
    public const array STORE_JOBS = [
        CatalogPermissions::LISTING_CHOOSE,
        CatalogPermissions::LISTING_SELLING,
        CatalogPermissions::LISTING_UNAVAILABLE,
        CatalogPermissions::LISTING_LABELS,
    ];

    public function __construct(
        private ProductReaders $readers,
        private CatalogProductReads $reads,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws ProductNotFound|Unauthorized
     */
    public function handle(ViewProduct $query): ProductView
    {
        $covered = $this->readers->authorize();
        $product = $this->reads->core($query->productId) ?? throw new ProductNotFound($query->productId);
        $tab = in_array($query->tab, ViewProduct::TABS, true) ? $query->tab : ViewProduct::DETAILS;

        // The Stores tab lists the variants too: each store's row is a variant's.
        $variants = $tab === ViewProduct::VARIANTS || $tab === ViewProduct::STORES ? $this->reads->variants($product->id) : null;
        $photos = $product->gallery;

        foreach ($variants ?? [] as $variant) {
            $photos = [...$photos, ...$variant->photos];
        }

        // Every photo the page shows, read together: the gallery's, and on the Variants tab theirs.
        $photoStates = [];

        foreach ($this->platform->mediaOf(array_values(array_unique($photos))) as $mediaId => $media) {
            $photoStates[$mediaId] = $media->variantsStatus->value ?? MediaVariantsStatus::Pending->value;
        }

        $stage = ProductStage::from($product->stage);
        $searchAndFilters = $tab === ViewProduct::SEARCH ? $this->reads->searchAndFilters($product->id) : null;
        $stores = $tab === ViewProduct::STORES ? $this->storeRows($product->id, $covered) : null;

        return new ProductView(
            $product,
            $tab,
            self::missing($product, array_intersect_key($photoStates, array_flip($product->gallery))),
            $photoStates,
            $this->readers->may(CatalogPermissions::PRODUCT_UPDATE, $product->onIn),
            $stage === ProductStage::Draft && $this->readers->may(CatalogPermissions::PRODUCT_PUBLISH, []),
            $this->readers->may(CatalogPermissions::PRODUCT_ARCHIVE, $product->onIn),
            $stage === ProductStage::Ready && $this->readers->may(CatalogPermissions::VARIANT_CORRECT_CODE, $product->onIn),
            options: $tab === ViewProduct::DETAILS ? $this->reads->options() : null,
            variants: $variants,
            attributes: $tab === ViewProduct::VARIANTS || $tab === ViewProduct::SEARCH ? $this->reads->attributeChoices() : null,
            searchWords: $searchAndFilters['words'] ?? null,
            filterValueIds: $searchAndFilters['valueIds'] ?? null,
            related: $tab === ViewProduct::RELATED ? $this->reads->related($product->id) : null,
            stores: $stores,
            labels: $tab === ViewProduct::STORES ? $this->reads->labelChoices() : null,
            storeJobs: $tab === ViewProduct::STORES ? array_combine(self::STORE_JOBS, array_map($this->readers->heldIn(...), self::STORE_JOBS)) : null,
        );
    }

    /**
     * What each store the reader covers does with the product - only those that chose it have a row.
     *
     * @param  list<string>|null  $covered  null for every store
     * @return array<string, StoreListingRow>
     */
    private function storeRows(string $productId, ?array $covered): array
    {
        $rows = $this->reads->storeRows($productId);

        return $covered === null ? $rows : array_intersect_key($rows, array_flip($covered));
    }

    /**
     * @param  array<string, string>  $photoStates  the gallery's photos' states
     * @return list<string>
     */
    private static function missing(ProductCore $product, array $photoStates): array
    {
        $missing = [];

        if ($product->nameEn === null || $product->slugEn === null) {
            $missing[] = 'name_en';
        }

        if ($product->descriptionAr === null) {
            $missing[] = 'description_ar';
        }

        if ($product->descriptionEn === null) {
            $missing[] = 'description_en';
        }

        if (! $product->categoryShowable) {
            $missing[] = 'category';
        }

        if (! $product->hasVariant) {
            $missing[] = 'variants';
        }

        if (! in_array(MediaVariantsStatus::Ready->value, $photoStates, true)) {
            $missing[] = 'photos';
        }

        return $missing;
    }
}
