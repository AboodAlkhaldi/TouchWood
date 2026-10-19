<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\AddSizeChoices;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Stock\InventoryChange;
use Modules\Inventory\Application\Stock\StockViews;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\ProviderOwnsStock;
use Modules\Inventory\Domain\Exception\StoreOff;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Shared\Application\Unauthorized;

/**
 * **Add Size's picker** (`AddSizeChoices`, inventory.md §4.3.3 I1, Q24), under **Manage Stock** in a store
 * with no provider - a wired store's stock is the provider's, so Add Size is not offered there and this
 * refuses it (`ProviderOwnsStock`). Every ready product's sizes, not archived, less those the Stock screen
 * lists already (switched on, or with a stock row): `searchVariants(anyReady:, except:)`. Picked, the size
 * is counted with `SetStock` - a stocktake starts its stock, and its row lists it from then on.
 *
 * A search also names the first few listed sizes it finds, for "Already listed". A fixed few queries.
 */
final readonly class AddSizeChoicesHandler
{
    public const string PERMISSION = InventoryPermissions::STOCK_MANAGE;

    public function __construct(
        private InventoryChange $change,
        private CatalogApi $catalog,
        private StoreWiring $wiring,
        private StockRows $stock,
        private StockViews $views,
    ) {}

    /**
     * @throws Unauthorized|InvalidInventoryInput|StoreOff|ProviderOwnsStock
     */
    public function handle(AddSizeChoices $query): SizeChoices
    {
        $storeId = $this->change->authorize(self::PERMISSION, $query->storeId)->storeId();

        if ($this->wiring->isWired($storeId)) {
            throw new ProviderOwnsStock;
        }

        $listed = array_values(array_unique([...$this->catalog->switchedOnVariantIds($storeId), ...$this->stock->variantIds($storeId)]));
        $found = $this->catalog->searchVariants($storeId, $query->search, except: $listed, anyReady: true, after: $query->after, limit: AddSizeChoices::PER_PAGE);
        $search = $query->search === null ? '' : trim($query->search);
        $already = $search === '' || $listed === [] ? [] : $this->catalog->searchVariants($storeId, $search, $listed, limit: AddSizeChoices::LISTED_NAMED)->rows;

        return new SizeChoices(
            array_map($this->views->size(...), $found->rows),
            $found->next,
            $found->total,
            array_map($this->views->size(...), $already),
        );
    }
}
