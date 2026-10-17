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

    /** The connection gone, its key and secret with it (sync.md §1.8). */
    public function delete(StoreId $store): void;
}
