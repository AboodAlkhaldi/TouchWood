<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\ListStock;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundVariantDto;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\InventorySettings;
use Modules\Inventory\Application\Stock\InventoryChange;
use Modules\Inventory\Application\Stock\StockViews;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\StoreOff;
use Modules\Inventory\Domain\Repository\Holds;
use Modules\Inventory\Domain\Repository\StockMovements;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Inventory\Domain\Repository\StoreProducts;
use Modules\Inventory\Domain\Service\StockFigures;
use Modules\Inventory\Domain\ValueObject\StockRow;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * **The Stock screen** (`ListStock`, inventory.md §4.3.3 I1), under **Manage Stock** in the store.
 * Inventory works out the sizes its filter keeps from **one query of its own** - every stock row the
 * store has - and Catalog pages them by its order - product name in the page's language, then the size's
 * place - through `searchVariants(only:)` (catalog.md amendment 23(a)):
 *
 * - **All**: the sizes switched on, and those with a stock row though switched off (§1.7);
 * - **Low**: of those, available at or below the threshold - a size with no row counts as 0 - archived
 *   ones left out, as the Low Stock list leaves them (§1.7);
 * - **Out of Stock**: available 0 on a size whose stock limits ordering (Q17) - in a store with no
 *   provider every size, a size with no row too; in a wired store a stock-dependent one - every size
 *   unless staff switched it off, a size with no row too (§9.2 #24);
 * - **Held**: a size orders hold pieces of;
 * - **Short**: orders hold more than there is (Q18).
 *
 * Then each page's figures from the rows already read, the provider's last change of each (a wired
 * store) and what orders hold of each, a query apiece. A fixed few queries however many rows - never one
 * a row. A read: no lock, nothing written.
 */
final readonly class ListStockHandler
{
    public const string PERMISSION = InventoryPermissions::STOCK_MANAGE;

    public function __construct(
        private InventoryChange $change,
        private PlatformApi $platform,
        private CatalogApi $catalog,
        private StoreWiring $wiring,
        private StockRows $stock,
        private StoreProducts $products,
        private StockMovements $movements,
        private Holds $holds,
        private StockViews $views,
    ) {}

    /**
     * @throws Unauthorized|InvalidInventoryInput|StoreOff
     */
    public function handle(ListStock $query): StockList
    {
        $storeId = $this->change->authorize(self::PERMISSION, $query->storeId)->storeId();

        if (! in_array($query->filter, ListStock::FILTERS, true)) {
            throw new InvalidInventoryInput('filter');
        }

        $wired = $this->wiring->isWired($storeId);
        $default = (int) $this->platform->setting(InventorySettings::LOW_STOCK_DEFAULT, $storeId)->value;
        $rows = $this->stock->ofStore($storeId);
        $listed = array_values(array_unique([...$this->catalog->switchedOnVariantIds($storeId), ...array_map('strval', array_keys($rows))]));
        $keeps = match ($query->filter) {
            ListStock::LOW => static fn (?StockRow $row): bool => StockFigures::free($row, $wired) <= ($row->threshold ?? $default),
            ListStock::OUT_OF_STOCK => static fn (?StockRow $row): bool => StockFigures::free($row, $wired) === 0 && StockFigures::counts($row, $wired),
            ListStock::HELD => static fn (?StockRow $row): bool => ($row->held ?? 0) > 0,
            ListStock::SHORT => static fn (?StockRow $row): bool => StockFigures::shortBy($row, $wired) > 0,
            default => static fn (?StockRow $row): bool => true,
        };
        $only = array_values(array_filter($listed, static fn (string $variantId): bool => $keeps($rows[$variantId] ?? null)));

        $found = $this->catalog->searchVariants(
            $storeId,
            $query->search,
            $only,
            after: $query->after,
            limit: ListStock::PER_PAGE,
            notArchived: $query->filter === ListStock::LOW,
        );
        $ids = array_map(static fn (FoundVariantDto $row): string => $row->variantId, $found->rows);
        $provider = $wired && $ids !== [] ? ($this->movements->lastProviderChanges([$storeId], $ids)[$storeId->value] ?? []) : [];
        $holds = $ids === [] ? [] : ($this->holds->summaries([$storeId], $ids)[$storeId->value] ?? []);
        // The page's products showing "last pieces": one query (no provider; a wired store has none).
        $lastPieces = $wired || $ids === [] ? [] : array_fill_keys($this->products->endingSoonOf($storeId, array_values(array_unique(array_map(static fn (FoundVariantDto $row): string => $row->productId, $found->rows)))), true);

        return new StockList(
            array_map(fn (FoundVariantDto $row): StockListRow => new StockListRow(
                $this->views->size($row),
                $this->views->figures($rows[$row->variantId] ?? null, $wired, $default, $provider[$row->variantId] ?? null, $holds[$row->variantId] ?? null),
                $wired ? null : isset($lastPieces[$row->productId]),
            ), $found->rows),
            $found->next,
            $found->total,
        );
    }
}
