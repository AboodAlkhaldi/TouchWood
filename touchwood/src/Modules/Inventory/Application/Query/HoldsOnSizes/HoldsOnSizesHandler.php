<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\HoldsOnSizes;

use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Query\StockViews\HoldsView;
use Modules\Inventory\Application\Stock\InventoryChange;
use Modules\Inventory\Application\Stock\StockViews;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\StoreOff;
use Modules\Inventory\Domain\Repository\Holds;
use Shared\Application\Unauthorized;

/**
 * **Holds before saving** (inventory.md §4.3.1, Q20), under **Manage Stock** in the store: for each size
 * asked, how many orders hold how many pieces of it - bought and gift apart - in **one query** however
 * many sizes. A size nothing holds answers 0; an id that is not one, the same. Said, never refused: a
 * count below what orders hold is saved and those orders flagged (§9.2 #19). A read: nothing written.
 */
final readonly class HoldsOnSizesHandler
{
    public const string PERMISSION = InventoryPermissions::STOCK_MANAGE;

    public function __construct(
        private InventoryChange $change,
        private Holds $holds,
    ) {}

    /**
     * @return array<string, HoldsView> each size as asked (lower case) => what orders hold of it
     *
     * @throws Unauthorized|InvalidInventoryInput|StoreOff
     */
    public function handle(HoldsOnSizes $query): array
    {
        $storeId = $this->change->authorize(self::PERMISSION, $query->storeId)->storeId();
        $ids = array_values(array_unique(array_map(static fn (string $id): string => strtolower(trim($id)), $query->variantIds)));
        $held = $ids === [] ? [] : ($this->holds->summaries([$storeId], $ids)[$storeId->value] ?? []);
        $answer = [];

        foreach ($ids as $id) {
            $answer[$id] = StockViews::holds($held[$id] ?? null);
        }

        return $answer;
    }
}
