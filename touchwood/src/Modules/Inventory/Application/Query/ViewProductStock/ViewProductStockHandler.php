<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\ViewProductStock;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\InventorySettings;
use Modules\Inventory\Application\Stock\ReaderStores;
use Modules\Inventory\Application\Stock\StockViews;
use Modules\Inventory\Domain\Repository\Holds;
use Modules\Inventory\Domain\Repository\StockMovements;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Inventory\Domain\Repository\StoreProducts;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * **A product's stock in every store the reader manages stock in** (`ViewProductStock`, inventory.md
 * §4.3.3, the product page's Inventory section through Catalog's `ProductStoreSections`): of the page's
 * stores, those where the reader holds **Manage Stock** - an off store only for whoever may switch
 * stores - each with its sizes' figures and the product's Last Pieces switch.
 *
 * **One read for every card**: each table is read once for all the stores - the stock rows, the product's
 * switch, the provider's last changes, the holds - so the tab costs a fixed few queries however many
 * stores and sizes (frontend.md §5). A reader who manages stock nowhere on the page costs nothing more
 * than the asking. A read: no lock, nothing written.
 */
final readonly class ViewProductStockHandler
{
    public function __construct(
        private ReaderStores $stores,
        private CatalogApi $catalog,
        private PlatformApi $platform,
        private StoreWiring $wiring,
        private StockRows $stock,
        private StoreProducts $products,
        private StockMovements $movements,
        private Holds $holds,
        private StockViews $views,
    ) {}

    /**
     * @return list<ProductStoreStock> in the page's order of its stores; a store the reader may not see left out
     */
    public function handle(ViewProductStock $query): array
    {
        $asked = array_map(static fn (string $id): string => strtolower(trim($id)), $query->storeIds);
        $mine = array_filter(
            $this->stores->authorize([InventoryPermissions::STOCK_MANAGE]),
            static fn (array $held, string $id): bool => in_array($id, $asked, true),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($mine === []) {
            return [];
        }

        $productId = strtolower(trim($query->productId));
        $variantIds = array_values(array_unique(array_map(static fn (string $id): string => strtolower(trim($id)), $query->variantIds)));
        $storeIds = array_map(static fn (array $held): StoreId => $held['store']->storeId(), array_values($mine));
        $wired = array_intersect_key(array_fill_keys(array_map('strtolower', $this->wiring->wiredStores()), true), $mine);
        $rows = $this->stock->inStores($storeIds, $variantIds);
        $lastPieces = array_fill_keys($this->products->endingSoonIn($storeIds, $productId), true);
        $holds = $this->holds->summaries($storeIds, $variantIds);
        // Which sizes are archived - the size or its product - in one read of Catalog: read-only (§4.3.1).
        $archived = [];

        foreach ($this->catalog->variantBriefs($variantIds) as $brief) {
            $archived[strtolower($brief->variantId)] = $brief->isArchived || $brief->productStage === ProductStage::Archived;
        }

        $provider = $wired === [] ? [] : $this->movements->lastProviderChanges(
            array_values(array_filter($storeIds, static fn (StoreId $store): bool => isset($wired[$store->value]))),
            $variantIds,
        );
        // Each store's default threshold, read once for all the stores: Platform answers every setting
        // from one snapshot of its settings, so no store costs a query of its own.
        $defaults = [];

        foreach ($storeIds as $store) {
            $defaults[$store->value] = (int) $this->platform->setting(InventorySettings::LOW_STOCK_DEFAULT, $store)->value;
        }

        $cards = [];

        foreach ($asked as $id) {
            if (! isset($mine[$id])) {
                continue;
            }

            $store = $mine[$id]['store'];
            $isWired = isset($wired[$id]);
            $default = $defaults[$id];
            $sizes = [];

            foreach ($variantIds as $variantId) {
                $sizes[] = new ProductSizeStock(
                    $variantId,
                    $this->views->figures($rows[$id][$variantId] ?? null, $isWired, $default, $provider[$id][$variantId] ?? null, $holds[$id][$variantId] ?? null),
                    $archived[$variantId] ?? false,
                );
            }

            $cards[] = new ProductStoreStock($id, $isWired, ! $store->isActive, $isWired ? null : isset($lastPieces[$id]), $sizes, $default);
        }

        return $cards;
    }
}
