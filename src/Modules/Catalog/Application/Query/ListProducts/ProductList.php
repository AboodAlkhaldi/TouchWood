<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListProducts;

use Modules\Catalog\Application\Query\Products\ProductFilter;
use Modules\Catalog\Application\Query\Products\ProductRow;

/**
 * A page of the products list, and whether another follows; each row's stores are only those the
 * reader covers (P4). The filter as it was applied - what the address asked, read as the list reads
 * it - for the page to show. Whether the reader may add a product (`catalog.product.create` in some
 * store that is on).
 */
final readonly class ProductList
{
    /**
     * @param  list<ProductRow>  $products
     */
    public function __construct(
        public array $products,
        public bool $more,
        public ProductFilter $filter,
        public bool $mayCreate,
    ) {}
}
