<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The categories screen (catalog.md §4.4 S2): every category of the tree, which it builds; the store
 * chosen in the page's own filter — whose menu order the page shows and orders —; what the reader
 * may do; and, when a deactivation is being prepared, the products it would reach.
 */
#[TypeScript]
final class CategoriesPage extends Data
{
    /**
     * @param  list<CategoryData>  $categories
     * @param  list<StoreOptionData>  $stores  the stores whose menu the reader orders
     * @param  list<ReachedProductData>|null  $reached
     */
    public function __construct(
        public array $categories,
        public ?string $storeCode,
        public ?string $storeName,
        public array $stores,
        public bool $mayManage,
        public bool $mayRank,
        public ?array $reached,
        public ?string $reachedFor,
    ) {}
}
