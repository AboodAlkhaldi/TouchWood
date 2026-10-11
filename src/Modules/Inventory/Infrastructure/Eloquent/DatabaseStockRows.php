<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Inventory\Domain\ValueObject\StockRow;
use Shared\Domain\ValueObject\StoreId;

/**
 * `inventory.stock` (inventory.md §5): every query names its store (catalog.md amendment 4(h)). Rows
 * are made and locked 500 sizes a statement, in size-id order across the chunks, so a whole store stays
 * under PostgreSQL's 65,535 parameters and every writer locks in one order.
 */
final readonly class DatabaseStockRows implements StockRows
{
    private const string TABLE = 'inventory.stock';

    private const int CHUNK = 500;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function lockFor(StoreId $store, array $sizes): array
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('Stock rows are made and locked inside the change\'s transaction.');
        }

        $variantIds = array_map('strval', array_keys($sizes));
        sort($variantIds);
        $now = CarbonImmutable::now();

        foreach (array_chunk($variantIds, self::CHUNK) as $chunk) {
            $this->db->table(self::TABLE)->insertOrIgnore(array_map(static fn (string $variantId): array => [
                'store_id' => $store->value,
                'variant_id' => $variantId,
                'product_id' => $sizes[$variantId],
                'updated_at' => $now,
            ], $chunk));
        }

        return $this->lockExisting($store, $variantIds);
    }

    public function lockExisting(StoreId $store, array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('Stock rows are locked inside the change\'s transaction.');
        }

        sort($variantIds);
        $locked = [];

        // One order for every writer (see StockRows), so two never wait on each other's rows.
        foreach (array_chunk($variantIds, self::CHUNK) as $chunk) {
            $rows = $this->db->table(self::TABLE)
                ->where('store_id', $store->value)
                ->whereIn('variant_id', $chunk)
                ->orderBy('variant_id')
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $locked[(string) $row->variant_id] = new StockRow(
                    (string) $row->variant_id,
                    (string) $row->product_id,
                    (int) $row->in_stock,
                    (int) $row->held,
                    $row->threshold === null ? null : (int) $row->threshold,
                    (bool) $row->stock_dependent,
                    (int) $row->returned_extra,
                );
            }
        }

        return $locked;
    }

    public function read(StoreId $store, array $variantIds): array
    {
        $rows = [];

        foreach (array_chunk($variantIds, self::CHUNK) as $chunk) {
            foreach ($this->db->table(self::TABLE)->where('store_id', $store->value)->whereIn('variant_id', $chunk)->get() as $row) {
                $rows[(string) $row->variant_id] = new StockRow(
                    (string) $row->variant_id, (string) $row->product_id, (int) $row->in_stock, (int) $row->held,
                    $row->threshold === null ? null : (int) $row->threshold, (bool) $row->stock_dependent, (int) $row->returned_extra,
                );
            }
        }

        return $rows;
    }

    public function reserve(StoreId $store, string $variantId, int $pieces, bool $withReturned): bool
    {
        $free = $withReturned ? 'in_stock + returned_extra - held' : 'in_stock - held';

        return $this->db->update(
            "UPDATE inventory.stock SET held = held + ?, updated_at = now() WHERE store_id = ? AND variant_id = ? AND {$free} >= ?",
            [$pieces, $store->value, $variantId, $pieces],
        ) === 1;
    }

    public function free(StoreId $store, string $variantId, int $pieces): void
    {
        $this->db->update(
            'UPDATE inventory.stock SET held = held - ?, updated_at = now() WHERE store_id = ? AND variant_id = ?',
            [$pieces, $store->value, $variantId],
        );
    }

    public function take(StoreId $store, string $variantId, int $pieces): void
    {
        $this->db->update(
            'UPDATE inventory.stock SET in_stock = in_stock - ?, held = held - ?, updated_at = now() WHERE store_id = ? AND variant_id = ?',
            [$pieces, $pieces, $store->value, $variantId],
        );
    }

    public function addReturnedExtra(StoreId $store, string $variantId, string $productId, int $pieces): void
    {
        $this->lockFor($store, [$variantId => $productId]);
        $this->db->update(
            'UPDATE inventory.stock SET returned_extra = returned_extra + ?, updated_at = now() WHERE store_id = ? AND variant_id = ?',
            [$pieces, $store->value, $variantId],
        );
    }

    public function setInStock(StoreId $store, string $variantId, int $inStock): void
    {
        $this->update($store, $variantId, ['in_stock' => $inStock]);
    }

    public function setThreshold(StoreId $store, string $variantId, ?int $threshold): void
    {
        $this->update($store, $variantId, ['threshold' => $threshold]);
    }

    public function setStockDependent(StoreId $store, string $variantId, bool $stockDependent): void
    {
        $this->update($store, $variantId, ['stock_dependent' => $stockDependent]);
    }

    /**
     * @param  array<string, int|bool|null>  $columns
     */
    private function update(StoreId $store, string $variantId, array $columns): void
    {
        $this->db->table(self::TABLE)
            ->where('store_id', $store->value)
            ->where('variant_id', $variantId)
            ->update([...$columns, 'updated_at' => CarbonImmutable::now()]);
    }
}
