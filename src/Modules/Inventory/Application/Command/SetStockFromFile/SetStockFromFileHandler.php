<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\SetStockFromFile;

use Illuminate\Support\Str;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Stock\HoldsShortNotice;
use Modules\Inventory\Application\Stock\InventoryChange;
use Modules\Inventory\Application\Stock\SizeInput;
use Modules\Inventory\Application\Stock\StockFacts;
use Modules\Inventory\Application\Stock\StoreWiring;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\QuantityInvalid;
use Modules\Inventory\Domain\Repository\StockMovements;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Inventory\Domain\ValueObject\MovementReason;
use Modules\Inventory\Domain\ValueObject\StockLimits;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Unauthorized;

/**
 * **The store file's stock** (inventory.md §1.8): the size's in stock **set to** the file's number - a
 * File movement, audited as the file's, from and to. Run by the system after Catalog's change commits
 * (Catalog's handler checked `catalog.listing.fill`), never inside it: Catalog holds its products' lock
 * there, and Inventory takes its store's lock before telling Catalog, so the two orders would meet. An
 * off store's too (§1.1 rule 6); a wired store's stock is the provider's and is left alone.
 */
final readonly class SetStockFromFileHandler
{
    /** The system's, for the file's switching on; staff set stock by a stocktake. */
    public const string PERMISSION = InventoryPermissions::STOCK_MANAGE;

    public function __construct(
        private InventoryChange $change,
        private StoreWiring $wiring,
        private SizeInput $sizes,
        private StockRows $stock,
        private StockMovements $movements,
        private HoldsShortNotice $notice,
        private StockFacts $facts,
    ) {}

    /**
     * @throws Unauthorized|InvalidInventoryInput|QuantityInvalid
     */
    public function handle(SetStockFromFile $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId)->storeId();

        if ($this->wiring->isWired($store)) {
            return;
        }

        if ($command->stock < 0 || $command->stock > StockLimits::MOST_PIECES) {
            throw new QuantityInvalid;
        }

        [$variantId, $productId] = $this->sizes->check([$command->variantId])[$command->variantId];

        $this->change->run($store, function () use ($store, $variantId, $productId, $command): array {
            $row = $this->stock->lockFor($store, [$variantId => $productId])[$variantId];
            $this->movements->record($store, $variantId, MovementReason::File, $row->inStock, $command->stock, null, 'file:'.Str::ulid());

            if ($row->inStock === $command->stock) {
                return [null, []];
            }

            $this->stock->setInStock($store, $variantId, $command->stock);

            if ($command->stock < $row->inStock && $command->stock < $row->held) {
                $this->notice->send($store, $variantId);
            }

            $this->facts->refresh($store, [$variantId]);

            return [null, [new AuditEntryDto(
                'inventory.stock.filed',
                'inventory.stock',
                $variantId,
                $store->value,
                AuditChanges::none()->changed('in_stock', $row->inStock, $command->stock),
            )]];
        });
    }
}
