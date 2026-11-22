<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\ViewStock;

/**
 * **One size's stock in a store** (inventory.md §4.3.3 `ViewStock`): the drawer the Stock screen opens
 * (`?size=`, Q3), from I1, I2 and the product page.
 */
final readonly class ViewStock
{
    public function __construct(
        public string $storeId,
        public string $variantId,
    ) {}
}
