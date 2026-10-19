<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\InventoryActions;

/**
 * **Why a row's button is drawn disabled** (inventory.md §4.3.1), in the page's language, for the rows'
 * own marks: the provider setting the store's stock, and a size archived in the catalog.
 */
final readonly class InventoryReasons
{
    public function __construct(
        public string $wired,
        public string $archived,
    ) {}
}
