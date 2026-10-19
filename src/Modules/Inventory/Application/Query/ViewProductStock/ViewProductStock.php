<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\ViewProductStock;

/**
 * **A product's stock in every store the reader manages stock in** (inventory.md §4.3.3, the product
 * page's Inventory section): its sizes as the page lists them, in these stores - one read for every
 * store card.
 */
final readonly class ViewProductStock
{
    /**
     * @param  list<string>  $variantIds  the product's sizes not archived, in its order
     * @param  list<string>  $storeIds  the page's stores
     */
    public function __construct(
        public string $productId,
        public array $variantIds,
        public array $storeIds,
    ) {}
}
