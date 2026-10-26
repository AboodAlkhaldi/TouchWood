<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Modules\Sales\Domain\Repository\OrderNumbers;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * `OrderNumbers` as one PostgreSQL sequence a store, from 1 (sales.md §1.3, §5; answer 35 as changed 2026-10-26): taken
 * outside any lock, so a placement that fails leaves a gap rather than making every placement in the
 * store wait. Made by the migration for the stores there were, and by `CreateOrderSequence` for each
 * store opened later.
 */
final readonly class OrderNumberSequences implements OrderNumbers
{
    public const int FIRST = 1;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function next(StoreId $store): int
    {
        // A store left without its numbers (a listener before this one failed) gets them now, rather
        // than every placement there failing — asked first: a missing sequence would abort the placement.
        if ($this->db->selectOne('SELECT to_regclass(?) IS NULL AS missing', [self::name($store->value)])->missing) {
            $this->create($store);
        }

        $row = $this->db->selectOne('SELECT nextval(?::regclass) AS number', [self::name($store->value)]);

        return (int) $row->number;
    }

    public function create(StoreId $store): void
    {
        $this->db->statement(sprintf('CREATE SEQUENCE IF NOT EXISTS %s START WITH %d', self::name($store->value), self::FIRST));
    }

    /** Every store's numbers made — the migration's, for the stores there are when it runs. */
    public static function createForStores(ConnectionInterface $db): void
    {
        $numbers = new self($db);

        foreach ($db->table('platform.stores')->pluck('id') as $storeId) {
            $numbers->create(StoreId::fromString((string) $storeId));
        }
    }

    /** The sequence's qualified name — the store's id checked first, as it is written into SQL. */
    public static function name(string $storeId): string
    {
        $id = strtolower($storeId);

        return Ulids::valid($id) ? 'sales.order_number_'.$id : throw new InvalidArgumentException('Not a store id.');
    }
}
