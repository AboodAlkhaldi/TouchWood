<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Inventory\Domain\Repository\LowSets;
use Modules\Inventory\Domain\ValueObject\LowChanges;
use Modules\Inventory\Domain\ValueObject\LowSet;
use Shared\Domain\ValueObject\StoreId;

/**
 * `inventory.low_sets` and `inventory.low_changes` (inventory.md §5, amendment 1). The lock is a
 * transaction-scoped advisory lock of its own per store - as the stock's (`DatabaseInventoryLocks`), but
 * never the same key, so a recount holding it never stops a change of stock.
 */
final readonly class DatabaseLowSets implements LowSets
{
    private const string MOMENT = 'Y-m-d H:i:s.uP';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function lock(StoreId $store): ?LowSet
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException("The store's low-set lock is taken inside the recount's transaction.");
        }

        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['inventory:low_set:'.$store->value], false);

        $row = $this->db->selectOne('SELECT low, out FROM inventory.low_sets WHERE store_id = ?', [$store->value], false);

        if ($row === null) {
            return null;
        }

        return new LowSet(self::ids((string) $row->low), self::ids((string) $row->out));
    }

    public function save(StoreId $store, LowSet $set, DateTimeImmutable $countedAt): void
    {
        $this->db->statement(
            'INSERT INTO inventory.low_sets (store_id, low, out, counted_at) VALUES (?, ?::jsonb, ?::jsonb, ?)'
            .' ON CONFLICT (store_id) DO UPDATE SET low = EXCLUDED.low, out = EXCLUDED.out, counted_at = EXCLUDED.counted_at',
            [$store->value, json_encode($set->low, JSON_THROW_ON_ERROR), json_encode($set->out, JSON_THROW_ON_ERROR), $countedAt->format(self::MOMENT)],
        );
    }

    public function record(StoreId $store, LowChanges $changes, DateTimeImmutable $changedAt): void
    {
        $entries = $changes->entries();

        if ($entries === []) {
            return;
        }

        // Two arrays, side by side: one statement however many changes.
        $this->db->statement(
            'INSERT INTO inventory.low_changes (store_id, variant_id, change, changed_at)'
            .' SELECT ?, size, kind, ? FROM unnest(?::text[], ?::text[]) AS entry(size, kind)',
            [
                $store->value,
                $changedAt->format(self::MOMENT),
                IdArray::of(array_map(static fn (array $entry): string => $entry[0], $entries)),
                '{'.implode(',', array_map(static fn (array $entry): string => $entry[1], $entries)).'}',
            ],
        );
    }

    public function changes(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): LowChanges
    {
        $lists = [LowChanges::LOW => [], LowChanges::OUT => [], LowChanges::BACK => []];

        foreach ($this->db->select(
            'SELECT change, variant_id FROM inventory.low_changes WHERE store_id = ? AND changed_at >= ? AND changed_at < ?'
            .' GROUP BY change, variant_id ORDER BY change, variant_id COLLATE "C"',
            [$store->value, $from->format(self::MOMENT), $to->format(self::MOMENT)],
            false,
        ) as $row) {
            $lists[(string) $row->change][] = (string) $row->variant_id;
        }

        return new LowChanges($lists[LowChanges::LOW], $lists[LowChanges::OUT], $lists[LowChanges::BACK]);
    }

    public function prune(DateTimeImmutable $before): int
    {
        return $this->db->delete('DELETE FROM inventory.low_changes WHERE changed_at < ?', [$before->format(self::MOMENT)]);
    }

    /**
     * @return list<string>
     */
    private static function ids(string $json): array
    {
        $ids = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($ids) ? array_values(array_map('strval', $ids)) : [];
    }
}
