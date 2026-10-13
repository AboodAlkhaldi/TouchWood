<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\LowStock;

/**
 * The Low Stock list of one store (inventory.md §1.7, §3).
 */
final readonly class LowStock
{
    public function __construct(
        public string $storeId,
    ) {}
}
