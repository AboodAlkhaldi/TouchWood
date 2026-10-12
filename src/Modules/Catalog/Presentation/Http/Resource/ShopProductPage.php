<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A product's page in the shop (catalog.md §4.5), or its "Not available now" page (P18): nothing to
 * choose, kept out of search engines. No price, no stock, no Add to Cart, no code.
 */
#[TypeScript]
final class ShopProductPage extends Data
{
    /**
     * @param  array<string, mixed>  $description  the structured text
     * @param  list<array<string, array<string, string>>>  $photos  the gallery
     * @param  list<ShopLabelData>  $labels
     * @param  list<ShopLinkData>  $path  the categories down to its own
     * @param  list<ShopChoiceData>  $choices
     * @param  list<ShopVariantData>  $variants
     * @param  list<ShopCardData>  $goesWith
     * @param  list<ShopCardData>  $mayAlsoLike
     */
    public function __construct(
        public string $productId,
        public string $name,
        public string $slug,
        public bool $available,
        public array $description,
        public array $photos,
        public array $labels,
        public array $path,
        public ?ShopLinkData $brand,
        public array $choices,
        public array $variants,
        public ?ShopWarrantyData $warranty,
        public ?ShopSellingData $selling,
        public array $goesWith,
        public array $mayAlsoLike,
        public ShopAddressData $address,
    ) {}
}
