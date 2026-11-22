<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\CountLowStock;

/**
 * A store's low sizes counted again for the menu (inventory.md §1.7, step 5); every store when none is
 * named.
 */
final readonly class CountLowStock
{
    public function __construct(
        public ?string $storeId = null,
    ) {}
}
