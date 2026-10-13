<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\SetStock;

use Illuminate\Support\Str;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Stock\HoldsShortNotice;
use Modules\Inventory\Application\Stock\InventoryChange;
use Modules\Inventory\Application\Stock\SizeInput;
use Modules\Inventory\Application\Stock\StockFacts;
use Modules\Inventory\Application\Stock\StoreWiring;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\ProviderOwnsStock;
use Modules\Inventory\Domain\Exception\QuantityInvalid;
use Modules\Inventory\Domain\Exception\StoreOff;
use Modules\Inventory\Domain\Repository\StockMovements;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Inventory\Domain\ValueObject\MovementReason;
use Modules\Inventory\Domain\ValueObject\StockLimits;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Unauthorized;

/**
 * **A stocktake** (inventory.md §1.5, §3): each size's in stock becomes the number counted, under
 * **Manage Stock** in that store with no provider - a wired store's stock is the provider's (§1.1 rule
 * 3). Every count goes in the ledger as a stocktake, **even one that finds the number right** (change
 * 0, owner 2026-10-11), so the history shows when a size was last counted; only a count that changed
 * the number is audited, from and to. In one transaction under the store's lock, the sizes' rows
 * locked so no hold slips under the count.
 */
final readonly class SetStockHandler
{
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
     * @throws Unauthorized|InvalidInventoryInput|StoreOff|ProviderOwnsStock|QuantityInvalid
     */
    public function handle(SetStock $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);
        $storeId = $store->storeId();

        if ($this->wiring->isWired($storeId)) {
            throw new ProviderOwnsStock;
        }

        // An array key that looks like a number arrives as one.
        $own = $this->sizes->check(array_map('strval', array_keys($command->counts)));

        /** @var array<string, int> $counts */
        $counts = [];
        /** @var array<string, string> $products */
        $products = [];

        foreach ($command->counts as $given => $count) {
            [$variantId, $productId] = $own[(string) $given];

            if ($count < 0 || $count > StockLimits::MOST_PIECES) {
                throw new QuantityInvalid;
            }

            // One size written two ways with two counts: which was meant cannot be told.
            if (isset($counts[$variantId]) && $counts[$variantId] !== $count) {
                throw new InvalidInventoryInput('variant');
            }

            $counts[$variantId] = $count;
            $products[$variantId] = $productId;
        }

        if ($counts === []) {
            return;
        }

        $this->change->run($storeId, function () use ($storeId, $counts, $products): array {
            // Read under the lock: the retried transaction runs this again (lesson 57).
            $rows = $this->stock->lockFor($storeId, $products);
            $entries = [];

            foreach ($counts as $variantId => $count) {
                $before = $rows[$variantId]->inStock;
                $this->movements->record($storeId, $variantId, MovementReason::Stocktake, $before, $count, null, 'stocktake:'.Str::ulid());

                if ($before === $count) {
                    continue;
                }

                $this->stock->setInStock($storeId, $variantId, $count);

                // Below what orders hold: every order holding it is named, none blocked (§1.5).
                if ($count < $before && $count < $rows[$variantId]->held) {
                    $this->notice->send($storeId, $variantId);
                }
                $entries[] = new AuditEntryDto(
                    'inventory.stock.counted',
                    'inventory.stock',
                    $variantId,
                    $storeId->value,
                    AuditChanges::none()->changed('in_stock', $before, $count),
                );
            }

            // Catalog told of what flipped, inside this transaction (§1.3, §2.3).
            $this->facts->refresh($storeId, array_map('strval', array_keys($counts)));

            return [null, $entries];
        });
    }
}
