<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\InventoryActions;

/**
 * **What Inventory's screens may offer in one store** (inventory.md §4.3.3 `InventoryActionsForReader`;
 * pricing.md §4.3.1 "Who may do what"): that the reader manages stock there (every Inventory page is that
 * one job), whether they may open a product's page (`catalog.product.view`), whether the store is wired
 * to a provider or off (a Super Admin's, marked **Off**), and - wired - why counting, Add or Remove, Last
 * Pieces and Add Size are not offered there: "The provider sets this store's stock." Offering is never
 * allowing: every handler asks again.
 */
final readonly class StoreInventoryActions
{
    public function __construct(
        public string $storeId,
        public bool $mayManageStock,
        public bool $mayViewProducts,
        public bool $wired,
        public bool $off,
        public ?string $handChangeRefusal,
    ) {}
}
