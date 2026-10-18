<?php

declare(strict_types=1);

namespace Modules\Inventory\Application;

use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Inventory\Application\Stock\InventoryChange;
use Modules\Inventory\Application\Stock\SizeInput;
use Modules\Inventory\Application\Stock\StockFacts;
use Modules\Inventory\Domain\Repository\StockMovements;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Inventory\Domain\Service\StockFigures;
use Modules\Inventory\Domain\ValueObject\MovementReason;
use Modules\Inventory\Domain\ValueObject\StockLimits;
use Modules\Inventory\Domain\ValueObject\StockRow;
use Modules\Inventory\Public\Contracts\ProviderStock;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Modules\Inventory\Public\Dto\ProviderStockResult;
use Shared\Domain\ValueObject\StoreId;

/**
 * The provider's stock in, and a store wired or unwired (inventory.md §2.5, §1.1 rule 3, §1.9; sync.md
 * §1.5, §1.8). Each batch is its own transaction under the store's lock, as every change
 * (`InventoryChange`), with no audit entry: Sync audits the read. Called from Sync's queued read or its
 * wiring, never inside Catalog's lock, so the store's lock and then Catalog's are taken in the order
 * every change takes them.
 */
final readonly class ProviderStockImpl implements ProviderStock
{
    /** Sizes a transaction, as the repair: a big store's checkout never waits on a whole read. */
    private const int CHUNK = 500;

    public function __construct(
        private StoreWiring $wiring,
        private CatalogApi $catalog,
        private InventoryChange $change,
        private SizeInput $sizes,
        private StockRows $stock,
        private StockMovements $movements,
        private StockFacts $facts,
    ) {}

    public function apply(StoreId $store, array $stock, string $readRef): ProviderStockResult
    {
        // The reference is the read's and the size's, in the ledger's 120 characters.
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $readRef) !== 1) {
            throw new InvalidArgumentException('A read reference is letters, digits, - and _, at most 64.');
        }

        if (! $this->wiring->isWired($store)) {
            return new ProviderStockResult(wired: false);
        }

        $numbers = $invalid = [];

        foreach ($stock as $variantId => $number) {
            // Sync drops fractions and keeps a number below 0 as 0 (sync.md §1.5 #2); the ledger's
            // bounds are held here all the same.
            if ($number >= 0 && $number <= StockLimits::MOST_PIECES) {
                $numbers[(string) $variantId] = $number;
            } else {
                $invalid[] = (string) $variantId;
            }
        }

        [$own, $archived, $notFound] = $this->sizes->sort(array_keys($numbers));
        // By the size's own id, in its order: the rows are locked in it (StockRows).
        $given = [];

        foreach ($own as $id => [$variantId, $productId]) {
            // Two ids written for one size: the first is taken, the other named, never dropped silently.
            if (isset($given[$variantId])) {
                $invalid[] = $id;

                continue;
            }

            $given[$variantId] = [$id, $productId];
        }

        ksort($given, SORT_STRING);
        $changed = $changes = [];

        foreach (array_chunk($given, self::CHUNK, true) as $chunk) {
            $done = $this->change->run($store, fn (): array => [$this->applyChunk($store, $chunk, $numbers, $readRef), []]);

            // Unwired between two batches: what is left is staff's now (sync.md §1.8).
            if ($done === null) {
                return new ProviderStockResult(false, $changed, $archived, $notFound, $invalid, $changes);
            }

            array_push($changed, ...$done[0]);
            array_push($changes, ...$done[1]);
        }

        return new ProviderStockResult(true, $changed, $archived, $notFound, $invalid, $changes);
    }

    public function wired(StoreId $store): void
    {
        if (! $this->wiring->isWired($store)) {
            throw new LogicException('Wire the store before telling Inventory: orderability is worked out for a wired store.');
        }

        // Every size told again, a size with no row too: an ordinary product counts on stock no more.
        $this->tellEverySize($store, static fn (): int => 0);
    }

    public function stopsWhenUnwired(StoreId $store): int
    {
        $switchedOn = $this->catalog->switchedOnVariantIds($store);
        $rows = $this->stock->read($store, $switchedOn);
        $stops = 0;

        foreach ($switchedOn as $variantId) {
            $stops += self::stops($rows[$variantId] ?? null) ? 1 : 0;
        }

        return $stops;
    }

    public function unwired(StoreId $store): int
    {
        if ($this->wiring->isWired($store)) {
            throw new LogicException('Unwire the store before telling Inventory: orderability is worked out for a store with no provider.');
        }

        $run = strtolower((string) Str::ulid());

        return $this->tellEverySize($store, function (array $chunk, array $switchedOn) use ($store, $run): int {
            $rows = $this->stock->lockExisting($store, $chunk);
            $stops = 0;

            foreach ($chunk as $variantId) {
                $row = $rows[$variantId] ?? null;
                $stops += isset($switchedOn[$variantId]) && self::stops($row) ? 1 : 0;

                // The pieces returned while the provider ruled are ours now: a correction, noted
                // "unwired" (sync.md §9.2 #12).
                if ($row !== null && $row->returnedExtra > 0) {
                    $after = $row->inStock + $row->returnedExtra;
                    $this->movements->record($store, $variantId, MovementReason::Correction, $row->inStock, $after, 'unwired', "unwired:{$run}:{$variantId}");
                    $this->stock->setInStock($store, $variantId, $after);
                    $this->stock->setReturnedExtra($store, $variantId, 0);
                }
            }

            return $stops;
        });
    }

    /**
     * Every size the store has switched on or a row for, told to Catalog again as the store is wired
     * now - the repair's set - after `$before` ran on each batch under the same lock.
     *
     * @param  callable(list<string>, array<string, int>): int  $before  answers what it counted
     */
    private function tellEverySize(StoreId $store, callable $before): int
    {
        $switchedOn = array_flip($this->catalog->switchedOnVariantIds($store));
        $sizes = array_values(array_unique([...$this->stock->variantIds($store), ...array_keys($switchedOn)]));
        sort($sizes, SORT_STRING);
        $counted = 0;

        foreach (array_chunk($sizes, self::CHUNK) as $chunk) {
            $counted += $this->change->run($store, function () use ($store, $chunk, $switchedOn, $before): array {
                $counted = $before($chunk, $switchedOn);
                $this->facts->refresh($store, $chunk, all: true);

                return [$counted, []];
            });
        }

        return $counted;
    }

    /**
     * @param  array<string, array{string, string}>  $chunk  own id => [the id as given, its product's id]
     * @param  array<string, int>  $numbers  id as given => the provider's number
     * @return array{list<string>, list<array{variantId: string, before: int, after: int}>}|null the sizes changed by the id as given, and each change; null when the store is no longer wired
     */
    private function applyChunk(StoreId $store, array $chunk, array $numbers, string $readRef): ?array
    {
        // Asked again under the store's lock, which unwiring takes too: no batch lands after it.
        if (! $this->wiring->isWired($store)) {
            return null;
        }

        // A size with no row that the provider has at 0 is 0 already: no row is made for it, so the
        // sizes the store does not sell stay off its Low Stock list.
        $existing = $this->stock->read($store, array_keys($chunk));
        $write = array_filter($chunk, static fn (array $size, string $variantId): bool => isset($existing[$variantId]) || $numbers[$size[0]] > 0, ARRAY_FILTER_USE_BOTH);
        $rows = $this->stock->lockFor($store, array_map(static fn (array $size): string => $size[1], $write));
        $changed = $told = $changes = [];

        foreach ($write as $variantId => [$id]) {
            $row = $rows[$variantId];
            $number = $numbers[$id];
            $reference = "provider:{$readRef}:{$variantId}";

            // The same number changes nothing; the same read run again finds its own movement.
            if ($row->inStock === $number || $this->movements->referenced($reference)) {
                continue;
            }

            $this->movements->record($store, $variantId, MovementReason::Provider, $row->inStock, $number, null, $reference);
            $this->stock->setInStock($store, $variantId, $number);

            // The provider's number changed: we follow it, and what was returned meanwhile goes (§1.9).
            if ($row->returnedExtra !== 0) {
                $this->stock->setReturnedExtra($store, $variantId, 0);
            }

            $changed[] = $id;
            $changes[] = ['variantId' => $id, 'before' => $row->inStock, 'after' => $number];
            $told[] = $variantId;
        }

        $this->facts->refresh($store, $told);

        return [$changed, $changes];
    }

    /** Whether a size orderable in the wired store stops once it is not: every product then counts on its stock. */
    private static function stops(?StockRow $row): bool
    {
        $after = $row === null ? null : new StockRow($row->variantId, $row->productId, $row->inStock + $row->returnedExtra, $row->held, $row->threshold, $row->stockDependent);

        return StockFigures::orderable($row, wired: true) && ! StockFigures::orderable($after, wired: false);
    }
}
