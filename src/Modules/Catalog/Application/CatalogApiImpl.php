<?php

declare(strict_types=1);

namespace Modules\Catalog\Application;

use Modules\Catalog\Application\Api\ApiReads;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\StoreListingRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Domain\ValueObject\ProductCode;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Catalog\Public\Dto\StoreVariantDto;
use Modules\Catalog\Public\Dto\VariantDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Catalog\Public\Enums\SaleMode;
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
        private ApiReads $reads,
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

    public function variantIdsOf(string $productId, bool $includeArchived = false): array
    {
        return $this->reads->variantIdsOf($productId, $includeArchived);
    }

    public function productIdsInCategory(string $categoryId): array
    {
        $category = $this->categories->find($categoryId);

        return $category === null ? [] : $this->products->idsInCategories([$category->id(), ...$this->categories->idsBelow($category->id())]);
    }

    public function switchedOnVariantIds(StoreId $store): array
    {
        return $this->reads->switchedOnVariantIds($store->value);
    }

    public function variants(array $variantIds): array
    {
        return $this->reads->variants($variantIds);
    }

    public function products(array $productIds): array
    {
        return $this->reads->products($productIds);
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
}
