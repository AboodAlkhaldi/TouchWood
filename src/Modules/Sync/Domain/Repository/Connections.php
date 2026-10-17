<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Repository;

use Modules\Sync\Domain\Model\Connection;
use Shared\Domain\ValueObject\StoreId;

/**
 * The stores' connections (sync.md §5 `sync.connections`), one per store at most.
 */
interface Connections
{
    public function find(StoreId $store): ?Connection;

    /** The store's connection, locked for the rest of the transaction - a change or an unwiring waits on another. */
    public function lockFor(StoreId $store): ?Connection;

    /** Whether the store is wired: a connection row, nothing decrypted. */
    public function exists(StoreId $store): bool;

    /**
     * A new connection, or the store's changed - its provider, address, fields, key and secret only, never
     * its state or last read; false when a new one finds the store wired meanwhile.
     */
    public function save(Connection $connection, bool $new): bool;

    /**
     * A read succeeded (sync.md §4): working again, when it last read - everything, for a full read -
     * and no error. Never locks the row: a read must not take it after Pricing's or Inventory's store
     * lock, which unwiring takes after it.
     */
    public function markRead(StoreId $store, bool $full): void;

    /** A read failed after its retries (§1.7): failing, saying why. Never locks the row either. */
    public function markFailing(StoreId $store, string $why): void;

    /** The connection gone, its key and secret with it (sync.md §1.8). */
    public function delete(StoreId $store): void;
}
