<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\AdjustStock;

use Illuminate\Support\Str;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Stock\HoldsShortNotice;
use Modules\Inventory\Application\Stock\InventoryChange;
use Modules\Inventory\Application\Stock\SizeInput;
use Modules\Inventory\Application\Stock\StockFacts;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\NoteRequired;
use Modules\Inventory\Domain\Exception\ProviderOwnsStock;
use Modules\Inventory\Domain\Exception\QuantityInvalid;
use Modules\Inventory\Domain\Exception\StockBelowZero;
use Modules\Inventory\Domain\Exception\StoreOff;
use Modules\Inventory\Domain\Repository\StockMovements;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Inventory\Domain\Service\HandAdjustment;
use Modules\Inventory\Domain\ValueObject\MovementReason;
use Modules\Inventory\Domain\ValueObject\StockLimits;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Unauthorized;

/**
 * **Adding or removing pieces by hand** (inventory.md §1.5, §3), under **Manage Stock** in that store
 * with no provider: each reason goes one way (`HandAdjustment`), a correction with its note. In stock
 * never goes below 0; it may go below what is held - those orders are flagged (`HoldsShort`, with
 * holds in step 3). One ledger line and one audit entry, from and to, in one transaction under the
 * store's lock, the size's row locked.
 */
final readonly class AdjustStockHandler
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
     * @throws Unauthorized|InvalidInventoryInput|StoreOff|ProviderOwnsStock|QuantityInvalid|NoteRequired|StockBelowZero
     */
    public function handle(AdjustStock $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);
        $storeId = $store->storeId();

        if ($this->wiring->isWired($storeId)) {
            throw new ProviderOwnsStock;
        }

        [$variantId, $productId] = $this->sizes->check([$command->variantId])[$command->variantId];
        // Trimmed first, so its length is the length kept.
        $note = HandAdjustment::note($command->note);
        $change = HandAdjustment::change($command->direction, $command->quantity, $command->reason, $note);
        $reason = MovementReason::from($command->reason);

        $this->change->run($storeId, function () use ($storeId, $variantId, $productId, $change, $reason, $note): array {
            $row = $this->stock->lockFor($storeId, [$variantId => $productId])[$variantId];
            $before = $row->inStock;
            $after = $before + $change;

            if ($after < 0) {
                throw new StockBelowZero;
            }

            if ($after > StockLimits::MOST_PIECES) {
                throw new QuantityInvalid;
            }

            $this->stock->setInStock($storeId, $variantId, $after);

            // Below what orders hold: every order holding it is named, none blocked (§1.5).
            if ($change < 0 && $after < $row->held) {
                $this->notice->send($storeId, $variantId);
            }
            $this->movements->record($storeId, $variantId, $reason, $before, $after, $note, 'hand:'.Str::ulid());

            $this->facts->refresh($storeId, [$variantId]);

            // The note stays in the ledger alone: free text is kept out of the audit log, kept forever.
            $changes = AuditChanges::none()->changed('in_stock', $before, $after)->changed('reason', null, $reason->value);

            return [null, [new AuditEntryDto('inventory.stock.adjusted', 'inventory.stock', $variantId, $storeId->value, $changes)]];
        });
    }
}
