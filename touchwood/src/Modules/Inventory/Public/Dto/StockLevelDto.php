<?php

declare(strict_types=1);

namespace Modules\Inventory\Public\Dto;

/**
 * **One size of `InventoryApi::stockLevels`** (inventory.md §2.1, §2.2, amendment 1) - for Ops' Stock
 * report. `inStock` is a wired store's provider's last number; `returnedExtra` the pieces returned there
 * and not yet in it (§1.9), 0 elsewhere; `available` what can be ordered or given now, never below 0;
 * `threshold` its own or the store's default. **`out`**: nothing available; **`low`**: 1 or more and at or
 * below the threshold - as the Low Stock list says, so an archived size is neither (§1.7).
 */
final readonly class StockLevelDto
{
    public function __construct(
        public string $variantId,
        public int $inStock,
        public int $returnedExtra,
        public int $held,
        public int $available,
        public int $threshold,
        public bool $countsOnStock,
        public bool $archived,
        public bool $low,
        public bool $out,
    ) {}
}
