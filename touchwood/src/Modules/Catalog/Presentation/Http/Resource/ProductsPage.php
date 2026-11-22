<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The products list (catalog.md §4.4 S8, P4): a page of products, newest first; the filters as
 * applied; the store chosen in the page's own filter - All Stores first -; whether the reader may
 * add a product, and what a new one may carry.
 */
#[TypeScript]
final class ProductsPage extends Data
{
    /**
     * @param  list<ProductRowData>  $products
     * @param  string|null  $after  the last product shown: the next page starts after it
     * @param  list<StoreOptionData>  $stores
     * @param  list<BrandOptionData>  $brands
     * @param  list<CategoryOptionData>  $categories
     */
    public function __construct(
        public array $products,
        public bool $more,
        public ?string $after,
        public ?string $search,
        public ?string $stage,
        public ?string $categoryId,
        public ?string $brandId,
        public ?string $storeState,
        public ?string $storeCode,
        public array $stores,
        public bool $mayCreate,
        public array $brands,
        public array $categories,
    ) {}
}
