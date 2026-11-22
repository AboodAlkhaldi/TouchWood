<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\ViewProductStock;

use Modules\Inventory\Application\Query\StockViews\StockFiguresView;

/**
 * One size of the product in one store card (inventory.md §4.3.3, the product page): its figures and its
 * one badge - In Stock a box with no provider, the provider's number in a wired store - with its
 * threshold and, wired, its Counts on Stock switch. **Archived** - the size or its product - it is read-only
 * (§4.3.1): its figures shown, no box and no change offered.
 */
final readonly class ProductSizeStock
{
    public function __construct(
        public string $variantId,
        public StockFiguresView $figures,
        public bool $archived = false,
    ) {}
}
