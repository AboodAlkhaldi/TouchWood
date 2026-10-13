<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

/**
 * One low size in a store (inventory.md §1.7): its figures, and its product when its stock row names it
 * - a size switched on with no row yet is named by Catalog.
 */
final readonly class LowSize
{
    public function __construct(
        public string $variantId,
        public ?string $productId,
        public int $inStock,
        public int $held,
        public int $free,
        public int $threshold,
    ) {}
}
