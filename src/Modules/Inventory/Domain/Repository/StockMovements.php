<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Repository;

use Modules\Inventory\Domain\ValueObject\MovementReason;
use Shared\Domain\ValueObject\StoreId;

/**
 * The ledger (inventory.md §1.6, §5 `inventory.stock_movements`): every change of in stock with its
 * before and after, who or what made it, and a unique reference - written once, never changed (the
 * database refuses an update or a delete).
 */
interface StockMovements
{
    /**
     * One change of a size's in stock, by whoever acts now.
     */
    public function record(StoreId $store, string $variantId, MovementReason $reason, int $before, int $after, ?string $note, string $reference): void;
}
