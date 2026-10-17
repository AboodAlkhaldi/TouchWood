<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Import;

use Illuminate\Support\Str;
use Modules\Catalog\Public\Contracts\ImportSection;
use Modules\Inventory\Domain\ValueObject\StockLimits;
use Modules\Inventory\Infrastructure\Queue\SetStockFromFileJob;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Shared\Domain\ValueObject\StoreId;

/**
 * **Inventory's part of a store file** (inventory.md §1.8; catalog.md §2.3): the file's page says the
 * stock each item will set, or why it will not; when an item is switched on, its stock is set by a job
 * queued once Catalog's change commits (`SetStockFromFileHandler` says why not inside it). A store
 * wired to a provider ignores it, the provider being its source; an item with no stock leaves it.
 */
final readonly class StockImportSection implements ImportSection
{
    public function __construct(
        private StoreWiring $wiring,
    ) {}

    public function lines(StoreId $store, ?string $price, ?int $stock): array
    {
        if ($stock === null) {
            return [];
        }

        if ($this->wiring->isWired($store)) {
            return [trans('inventory::import.stock_ignored')];
        }

        return [self::keeps($stock)
            ? trans('inventory::import.stock_set', ['stock' => $stock])
            : trans('inventory::import.stock_invalid', ['stock' => $stock])];
    }

    public function accepted(StoreId $store, array $variantIds, ?string $price, ?int $stock): void
    {
        if ($stock === null || ! self::keeps($stock) || $this->wiring->isWired($store)) {
            return;
        }

        foreach ($variantIds as $variantId) {
            SetStockFromFileJob::dispatch($store->value, $variantId, $stock, 'file:'.Str::ulid())->afterCommit();
        }
    }

    /** A number the stock column keeps: whole, from 0. */
    private static function keeps(int $stock): bool
    {
        return $stock >= 0 && $stock <= StockLimits::MOST_PIECES;
    }
}
