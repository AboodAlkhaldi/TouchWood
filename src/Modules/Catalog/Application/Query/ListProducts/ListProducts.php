<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListProducts;

use Modules\Catalog\Application\Query\Products\ProductFilter;

/**
 * The products list (catalog.md §4.4 S8, P4): every product, newest first, 50 at a time.
 */
final readonly class ListProducts
{
    public function __construct(
        public ProductFilter $filter = new ProductFilter,
        public int $perPage = 50,
    ) {}
}
