<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Repository;

use DateTimeImmutable;
use Modules\Inventory\Domain\ValueObject\LedgerLine;
use Modules\Inventory\Domain\ValueObject\LedgerNote;
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
     * One change of a size's in stock, by whoever acts now - its note as staff typed it, or one of
     * Inventory's own kept as a code and its values (`LedgerNote`).
     */
    public function record(StoreId $store, string $variantId, MovementReason $reason, int $before, int $after, string|LedgerNote|null $note, string $reference): void;

    /** Whether a movement with this reference was recorded: work run again records nothing twice. */
    public function referenced(string $reference): bool;

    /**
     * **A size's ledger, newest first** (§4.3.3 `StockHistory`): the lines before `$beforeId` (all,
     * without one), at most `$limit`, in one query - each shipment's and return's order named.
     *
     * @return list<LedgerLine>
     */
    public function history(StoreId $store, string $variantId, ?int $beforeId, int $limit): array;

    /**
     * **When the provider last changed each size's number** (§4.3.1, Q23): the moment of each size's
     * last `PROVIDER` line in each store - a number sent again unchanged writes none - in one query. A
     * size the provider never sent is left out.
     *
     * @param  list<StoreId>  $stores
     * @param  list<string>  $variantIds
     * @return array<string, array<string, DateTimeImmutable>> store id => variant id => when
     */
    public function lastProviderChanges(array $stores, array $variantIds): array;
}
