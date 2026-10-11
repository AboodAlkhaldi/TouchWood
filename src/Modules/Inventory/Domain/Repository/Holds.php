<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Repository;

use DateTimeImmutable;
use Modules\Inventory\Domain\ValueObject\Hold;
use Modules\Inventory\Domain\ValueObject\HoldLine;
use Shared\Domain\ValueObject\StoreId;

/**
 * Orders' holds and their lines, and each return counted once (inventory.md §5 `inventory.holds`,
 * `hold_lines`, `return_lines`).
 */
interface Holds
{
    /** The order's hold, its row locked for the rest of the transaction; null when it has none. */
    public function lockForOrder(string $orderId): ?Hold;

    public function create(Hold $hold): void;

    /** A line written as it is now: added, its quantity or shipped pieces changed, or ended. */
    public function saveLine(string $holdId, HoldLine $line): void;

    /**
     * @return list<string> the orders whose hold has expired by then and still holds a line open
     */
    public function expiredOrderIds(DateTimeImmutable $at): array;

    /**
     * @return list<string> every order with an open line on the size there that counts on stock
     */
    public function ordersHolding(StoreId $store, string $variantId): array;

    /** Whether this return's line was counted already. */
    public function returnCounted(string $returnId, string $variantId, bool $gift): bool;

    public function recordReturn(string $returnId, string $variantId, bool $gift, StoreId $store, string $orderId, int $quantity): void;
}
