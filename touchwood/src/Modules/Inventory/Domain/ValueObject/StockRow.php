<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\ValueObject;

/**
 * A size's stock in a store, as `inventory.stock` holds it (inventory.md §1.2, §5). A size with no row
 * counts as 0 (§9.2 #6) and, in a wired store, as stock-dependent: the switch is on by default
 * (owner, 2026-10-28; §9.2 #24).
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
        public int $returnedExtra = 0,
        public ?bool $orderablePushed = null,
        public bool $endingSoonPushed = false,
    ) {}
}
