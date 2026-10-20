<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The stock-dependent switch itself** (inventory.md §1.2, §4.2), shared by staff's switch
 * (`SetStockDependent`) and `InventoryApi::makeStockDependent` (Promotions' gift level, owner
 * 2026-10-19), so the two write the same rows, the same audit entry and tell Catalog the same way.
 *
 * Runs inside the change's transaction, after the store's lock (`InventoryChange::run`); the rows are
 * locked in size-id order (`StockRows`), as every writer to the stock does.
 */
final readonly class StockDependentSwitch
{
    public function __construct(
        private StockRows $stock,
        private StockFacts $facts,
    ) {}

    /**
     * @param  array<string, string>  $products  each size's own id => its product's id
     * @return array{list<string>, list<AuditEntryDto>} the sizes that changed, in size-id order, and their audit entries
     */
    public function set(StoreId $store, array $products, bool $on): array
    {
        // A size with no row is off: a row is made only for one switched on.
        $rows = $on
            ? $this->stock->lockFor($store, $products)
            : $this->stock->lockExisting($store, array_map('strval', array_keys($products)));
        $sizes = array_map('strval', array_keys($products));
        sort($sizes);
        $changed = [];
        $entries = [];

        foreach ($sizes as $variantId) {
            $before = isset($rows[$variantId]) && $rows[$variantId]->stockDependent;

            if ($before === $on) {
                continue;
            }

            $this->stock->setStockDependent($store, $variantId, $on);
            $changed[] = $variantId;
            $entries[] = new AuditEntryDto(
                'inventory.stock.stock_dependent_set',
                'inventory.stock',
                $variantId,
                $store->value,
                AuditChanges::none()->changed('stock_dependent', $before, $on),
            );
        }

        // Catalog told of what flipped, inside this transaction (§1.3, §2.3).
        $this->facts->refresh($store, $sizes);

        return [$changed, $entries];
    }
}
