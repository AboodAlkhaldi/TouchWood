<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Repository;

use DateTimeImmutable;
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

    /** The store's notification secret alone - null when not wired, or when it cannot be decrypted. */
    public function notificationSecret(StoreId $store): ?string;

    /**
     * @return list<string> every wired store, on or off (sync.md §1.1 rule 8: an off store is still read)
     */
    public function wiredStores(): array;

    /**
     * A new connection, or the store's changed - its provider, address, fields, key and secret only, never
     * its state or last read; false when a new one finds the store wired meanwhile.
     */
    public function save(Connection $connection, bool $new): bool;

    /**
     * A read succeeded (sync.md §4): working again, no error. One that covers every change up to its
     * start - the hour's, a full read's - moves the time the next hourly read asks from to that start
     * (and the last full read, for a full one); a notice's or one code's does not, so a lost notice is
     * still caught within the hour (§1.3). Never locks the row: a read must not take it after Pricing's
     * or Inventory's store lock, which unwiring takes after it.
     */
    public function markRead(StoreId $store, ?DateTimeImmutable $changesUpTo, bool $full): void;

    /**
     * When this store's present wiring began - null when not wired. A read compares it before each batch:
     * a store unwired and wired again meanwhile is another wiring, with its own reads.
     */
    public function wiredAt(StoreId $store): ?string;

    /** A read failed after its retries (§1.7): failing, saying why. Never locks the row either. */
    public function markFailing(StoreId $store, string $why): void;

    /** The connection gone, its key and secret with it (sync.md §1.8). */
    public function delete(StoreId $store): void;
}
