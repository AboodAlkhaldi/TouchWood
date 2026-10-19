<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\ViewProductStock;

use Modules\Inventory\Application\Query\StockViews\StockFiguresView;

/**
 * One size of the product in one store card (inventory.md §4.3.3, the product page): its figures and its
 * one badge - In Stock a box with no provider, the provider's number in a wired store - with its
 * threshold and, wired, its Counts on Stock switch.
 */
final readonly class ProductSizeStock
{
    public function __construct(
        public string $variantId,
        public StockFiguresView $figures,
    ) {}
}
