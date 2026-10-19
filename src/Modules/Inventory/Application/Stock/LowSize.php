<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

use Modules\Inventory\Domain\ValueObject\StockRow;

/**
 * One low size in a store (inventory.md §1.7): its figures, and its product when its stock row names it
 * - a size switched on with no row yet is named by Catalog - and the row itself, for the list's figures
 * and badge (none for a size with no row: it counts as 0).
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
        public ?StockRow $row = null,
    ) {}
}
