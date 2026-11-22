<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\LowStock;

/**
 * The Low Stock list of one store (inventory.md §1.7, §3, §4.3.3 I2): 50 at a time after the cursor of
 * the page before.
 */
final readonly class LowStock
{
    public const int PER_PAGE = 50;

    public function __construct(
        public string $storeId,
        public ?string $after = null,
    ) {}
}
