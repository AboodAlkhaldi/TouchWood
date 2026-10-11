<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A category's page in the shop (catalog.md §4.5): the path to it, its name, its sub-categories,
 * its products 24 at a time, the filters and the sort.
 */
#[TypeScript]
final class ShopCategoryPage extends Data
{
    /**
     * @param  list<ShopLinkData>  $path  the categories above it, from the top
     * @param  list<ShopLinkData>  $children
     * @param  list<ShopCardData>  $products
     * @param  string|null  $next  where Show More starts; null on the last page
     */
    public function __construct(
        public string $name,
        public string $slug,
        public array $path,
        public array $children,
        public array $products,
        public ?string $next,
        public ShopFiltersData $filters,
        public ShopAddressData $address,
    ) {}
}
