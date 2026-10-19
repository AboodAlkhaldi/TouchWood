<?php

declare(strict_types=1);

namespace Modules\Catalog\Application;

use Illuminate\Contracts\Foundation\Application;
use Modules\Catalog\Application\Api\ApiReads;
use Modules\Catalog\Application\Api\ApiSearches;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\StoreListingRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Domain\ValueObject\ProductCode;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\BrandDto;
use Modules\Catalog\Public\Dto\CategoryDto;
use Modules\Catalog\Public\Dto\FoundProductsDto;
use Modules\Catalog\Public\Dto\FoundVariantsDto;
use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Catalog\Public\Dto\StoreVariantDto;
use Modules\Catalog\Public\Dto\VariantDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Platform\Public\Enums\ShopperKind;
use Shared\Domain\Text\LatinDigits;
use Shared\Domain\ValueObject\StoreId;

/**
 * catalog.md §2.1. Plain reads: no lock, no transaction — each answers one question about one moment.
 */
final readonly class CatalogApiImpl implements CatalogApi
{
    public function __construct(
        private ProductRepository $products,
        private VariantRepository $variants,
        private StoreListingRepository $listings,
        private CategoryRepository $categories,
        private BrandRepository $brands,
        private ApiReads $reads,
        private ApiSearches $searches,
        private Application $app,
    ) {}

    public function variant(string $variantId): ?VariantDto
    {
        return $this->reads->variants([$variantId])[strtolower(trim($variantId))] ?? null;
    }

    public function variantByCode(string $code): ?VariantDto
    {
        try {
            $variant = $this->variants->carrying(ProductCode::of($code)->value);
        } catch (InvalidCatalogAttribute) {
            return null;
        }

        return $variant === null ? null : $this->variant($variant->id());
    }

    public function variantsByCodes(array $codes): array
    {
        // Each code as given => the code as Catalog keeps it (its digits Latin, spaces around trimmed).
        $kept = [];

        foreach ($codes as $code) {
            try {
                $kept[$code] = ProductCode::of($code)->value;
            } catch (InvalidCatalogAttribute) {
                // Not a code: no variant carries it.
            }
        }

        $ids = $this->reads->variantIdsByCodes(array_values(array_unique($kept)));
        $variants = [];

        foreach (array_chunk(array_values($ids), 1000) as $chunk) {
            $variants += $this->reads->variants($chunk);
        }

        $byCode = [];

        foreach ($kept as $given => $code) {
            $variant = $variants[$ids[$code] ?? ''] ?? null;

            if ($variant !== null) {
                $byCode[(string) $given] = $variant;
            }
        }

        return $byCode;
    }

    public function variantIdsOf(string $productId, bool $includeArchived = false): array
    {
        return $this->reads->variantIdsOf($productId, $includeArchived);
    }

    public function variantIdsOfProducts(array $productIds, bool $includeArchived = false): array
    {
        return $this->reads->variantIdsOfProducts($productIds, $includeArchived);
    }

    public function productIdsInCategory(string $categoryId): array
    {
        // Two queries, never the category itself (stage 5's screens step 3, a discount's page within its
        // budget): an unknown category has nothing below it and no product in it, so it holds none.
        $id = strtolower(trim($categoryId));

        return $this->products->idsInCategories([$id, ...$this->categories->idsBelow($id)]);
    }

    public function category(string $categoryId): ?CategoryDto
    {
        $category = $this->categories->find($categoryId);

        return $category === null ? null : new CategoryDto(
            $category->id(),
            $category->parentId(),
            $category->name()->ar,
            $category->name()->en,
            $category->isActive(),
        );
    }

    public function brand(string $brandId): ?BrandDto
    {
        $brand = $this->brands->find($brandId);

        return $brand === null ? null : new BrandDto($brand->id(), $brand->name()->ar, $brand->name()->en, $brand->isActive());
    }

    public function brands(array $brandIds): array
    {
        return $this->reads->brands($brandIds);
    }

    public function activeBrands(): array
    {
        return $this->reads->activeBrands();
    }

    public function productsSoldIn(StoreId $store, array $productIds): array
    {
        return $this->reads->productsSoldIn($store->value, $productIds);
    }

    public function productCards(StoreId $store, string $locale, array $productIds): array
    {
        return $this->reads->productCards($store->value, $locale, $productIds);
    }

    public function storeVariants(StoreId $store, array $variantIds): array
    {
        return $this->reads->storeVariants($store->value, $variantIds);
    }

    public function productsOpenTo(StoreId $store, ShopperKind $viewer, array $productIds): array
    {
        return $this->reads->productsOpenTo($store->value, $viewer, $productIds);
    }

    public function switchedOnVariantIds(StoreId $store): array
    {
        return $this->reads->switchedOnVariantIds($store->value);
    }

    public function variants(array $variantIds): array
    {
        return $this->reads->variants($variantIds);
    }

    public function variantBriefs(array $variantIds): array
    {
        return $this->reads->variantBriefs($variantIds);
    }

    public function products(array $productIds): array
    {
        return $this->reads->products($productIds);
    }

    public function lineFacts(array $variantIds): array
    {
        return $this->reads->lineFacts($variantIds);
    }

    public function product(string $productId): ?ProductDto
    {
        $product = $this->products->find($productId);

        return $product === null ? null : new ProductDto(
            $product->id(),
            $product->name()->ar,
            $product->name()->en,
            $product->stage(),
            $product->brandId(),
            $product->categoryId(),
            $product->warrantyId(),
        );
    }

    public function storeVariant(StoreId $store, string $variantId): ?StoreVariantDto
    {
        $variant = $this->variants->find($variantId);
        $product = $variant === null ? null : $this->products->find($variant->productId());

        if ($variant === null || $product === null) {
            return null;
        }

        $listing = $this->listings->of($store->value, $product->id());
        $row = $listing->variants()[$variant->id()] ?? ['active' => false, 'unavailable' => false, 'retail' => false, 'wholesale' => false];
        $unavailable = $listing->notAvailableNow() || $row['unavailable'];
        $modes = [];

        if ($row['retail']) {
            $modes[] = SaleMode::Retail;
        }

        if ($row['wholesale']) {
            $modes[] = SaleMode::Wholesale;
        }

        $limits = $listing->limits();

        return new StoreVariantDto(
            $store->value,
            $variant->id(),
            $product->id(),
            $row['active'],
            // Until Inventory pushes it (§2.2): ready, switched on, not archived, neither it nor its
            // product "Not available now" there (§1.3), and not hidden with its category or brand —
            // hidden is inactive (owner, 2026-10-05, amendment 5(j)) — and no price needed yet (5(b)).
            $product->stage() === ProductStage::Ready && $row['active'] && ! $variant->isArchived() && ! $unavailable
                && ! $product->hiddenByCategory() && ! $product->hiddenByBrand(),
            $unavailable,
            $modes,
            $limits->retailMinimum,
            $limits->retailMaximum,
            $limits->wholesaleMinimum,
            $limits->wholesaleMaximum,
        );
    }

    public function searchVariants(StoreId $store, ?string $text, ?array $only = null, ?array $except = null, bool $soldWholesale = false, bool $anyReady = false, ?string $after = null, int $limit = 50, bool $notArchived = false, bool $withTotal = true): FoundVariantsDto
    {
        return $this->searches->variants($store->value, self::text($text), $only, $except, $soldWholesale, $anyReady, $after, $limit, $this->locale(), $notArchived, $withTotal);
    }

    public function searchProducts(StoreId $store, ?string $text, bool $readySwitchedOn, ?array $only = null, ?string $after = null, int $limit = 50): FoundProductsDto
    {
        return $this->searches->products($store->value, self::text($text), $readySwitchedOn, $only, $after, $limit, $this->locale());
    }

    public function categoriesWithPaths(array $ids): array
    {
        return $this->searches->categoriesWithPaths($ids);
    }

    public function productIdsInCategories(array $categoryIds): array
    {
        return $this->searches->productIdsInCategories($categoryIds);
    }

    public function activeCategories(): array
    {
        return array_values($this->searches->categoriesWithPaths($this->searches->activeCategoryIds()));
    }

    public function productsInCategory(string $categoryId, ?string $text, ?string $after = null, int $limit = 50): FoundProductsDto
    {
        return $this->searches->productsInCategory($categoryId, self::text($text), $after, $limit, $this->locale());
    }

    public function wholesaleOf(StoreId $store, array $variantIds): array
    {
        return $this->searches->wholesaleOf($store->value, $variantIds);
    }

    public function resolveVariant(string $productId, array $valueIds): ?string
    {
        // A product with no variant attributes has one variant, made of no values: nothing picked names it.
        $picked = array_values(array_unique(array_map(static fn (string $id): string => strtolower(trim($id)), $valueIds)));
        sort($picked);

        foreach ($this->variants->ofProduct(strtolower(trim($productId))) as $variant) {
            $made = array_values($variant->combination()->valueIds);
            sort($made);

            if (! $variant->isArchived() && $made === $picked) {
                return $variant->id();
            }
        }

        return null;
    }

    /**
     * A search as the products list reads one (ListProductsHandler): bytes that are not text dropped,
     * Arabic-Indic digits as Latin, the ends trimmed; nothing left is no search.
     */
    private static function text(?string $text): ?string
    {
        $text = $text === null ? '' : trim(LatinDigits::of(mb_scrub($text, 'UTF-8')));

        return $text === '' ? null : $text;
    }

    /**
     * The page's language, which a store-wide list is ordered in (amendment 23): Arabic unless English.
     *
     * @return 'ar'|'en'
     */
    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
