<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\ViewStock;

use Modules\Catalog\Public\Contracts\CatalogApi;
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
use Modules\Inventory\Public\Contracts\StoreWiring;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * **A size's drawer** (`ViewStock`, inventory.md §4.3.3), under **Manage Stock** in the store: any size
 * Catalog has - switched on or not, archived or not (an archived one read-only, marked) - with its
 * figures, badge, threshold, Last Pieces or Counts on Stock, the provider's last change and its holds.
 * A size Catalog does not have is invalid input. A fixed few queries. A read: no lock, nothing written.
 */
final readonly class ViewStockHandler
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
    public function handle(ViewStock $query): SizeStockView
    {
        $storeId = $this->change->authorize(self::PERMISSION, $query->storeId)->storeId();
        $found = $this->catalog->searchVariants($storeId, null, [$query->variantId], limit: 1)->rows[0] ?? throw new InvalidInventoryInput('variant', [$query->variantId]);
        $id = $found->variantId;
        $wired = $this->wiring->isWired($storeId);
        $default = (int) $this->platform->setting(InventorySettings::LOW_STOCK_DEFAULT, $storeId)->value;
        $row = $this->stock->read($storeId, [$id])[$id] ?? null;
        $holds = $this->holds->summaries([$storeId], [$id])[$storeId->value][$id] ?? null;
        $provider = $wired ? ($this->movements->lastProviderChanges([$storeId], [$id])[$storeId->value][$id] ?? null) : null;

        return new SizeStockView(
            $this->views->size($found),
            $this->views->figures($row, $wired, $default, $provider, $holds),
            $wired ? null : $this->products->endingSoon($storeId, $found->productId),
            StockViews::holds($holds),
        );
    }
}
