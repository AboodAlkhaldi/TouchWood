<?php

declare(strict_types=1);

namespace Modules\Catalog\Application;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Model\Variant;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\StoreListingRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Domain\ValueObject\ProductCode;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Catalog\Public\Dto\StoreVariantDto;
use Modules\Catalog\Public\Dto\VariantDto;
use Modules\Catalog\Public\Dto\VariantValueDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * catalog.md §2.1. Plain reads: no lock, no transaction — each answers one question about one moment.
 */
final readonly class CatalogApiImpl implements CatalogApi
{
    public function __construct(
        private ProductRepository $products,
        private VariantRepository $variants,
        private AttributeRepository $attributes,
        private StoreListingRepository $listings,
    ) {}

    public function variant(string $variantId): ?VariantDto
    {
        $variant = $this->variants->find($variantId);

        return $variant === null ? null : $this->toDto($variant);
    }

    public function variantsByCode(string $code): array
    {
        try {
            $code = ProductCode::of($code)->value;
        } catch (InvalidCatalogAttribute) {
            return [];
        }

        $productId = $this->products->codeHolder($code);

        if ($productId === null) {
            return [];
        }

        return array_values(array_map(
            $this->toDto(...),
            array_filter($this->variants->ofProduct($productId), static fn (Variant $variant): bool => $variant->code()->value === $code),
        ));
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
            // product "Not available now" there (§1.3) — and no price needed yet (amendment 5(b)).
            $product->stage() === ProductStage::Ready && $row['active'] && ! $variant->isArchived() && ! $unavailable,
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
        $picked = array_values(array_unique(array_map(static fn (string $id): string => strtolower(trim($id)), $valueIds)));
        sort($picked);

        if ($picked === []) {
            return null;
        }

        foreach ($this->variants->ofProduct(strtolower(trim($productId))) as $variant) {
            $made = array_values($variant->combination()->valueIds);
            sort($made);

            if (! $variant->isArchived() && $made === $picked) {
                return $variant->id();
            }
        }

        return null;
    }

    private function toDto(Variant $variant): VariantDto
    {
        $values = [];

        foreach ($variant->combination()->valueIds as $attributeId => $valueId) {
            $attribute = $this->attributes->find((string) $attributeId);
            $value = $this->attributes->findValue($valueId);

            if ($attribute !== null && $value !== null) {
                $values[] = new VariantValueDto(
                    $attribute->id(),
                    new TranslatedTextDto($attribute->name()->ar, $attribute->name()->en),
                    $value->id(),
                    new TranslatedTextDto($value->name()->ar, $value->name()->en),
                );
            }
        }

        $measures = $variant->measures();

        return new VariantDto(
            $variant->id(),
            $variant->productId(),
            $variant->code()->value,
            $values,
            $measures->weightGrams,
            $measures->lengthMm,
            $measures->widthMm,
            $measures->heightMm,
            $variant->isArchived(),
        );
    }
}
