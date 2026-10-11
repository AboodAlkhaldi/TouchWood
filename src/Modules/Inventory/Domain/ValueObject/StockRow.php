<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\ValueObject;

/**
 * A size's stock in a store, as `inventory.stock` holds it (inventory.md §1.2, §5). A size with no row
 * counts as 0 in a store with no provider (§9.2 #6).
 */
final readonly class StockRow
{
    public function __construct(
        public string $variantId,
        public string $productId,
        public int $inStock,
        public int $held,
        public ?int $threshold,
        public bool $stockDependent,
    ) {}
}
