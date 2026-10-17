<?php

declare(strict_types=1);

namespace Modules\Inventory\Application;

use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Inventory\Application\Stock\HoldsShortNotice;
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
 * The provider's stock in, and a store unwired (inventory.md §2.5, §1.1 rule 3, §1.9; sync.md §1.5,
 * §1.8). Each batch is its own transaction under the store's lock, as every change (`InventoryChange`),
 * with no audit entry: Sync audits the read. Called from Sync's queued read, never inside Catalog's
 * lock, so the store's lock and then Catalog's are taken in the order every change takes them.
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
        private HoldsShortNotice $notice,
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
            $given[$variantId] = [$id, $productId];
        }

        ksort($given, SORT_STRING);
        $changed = [];

        foreach (array_chunk($given, self::CHUNK, true) as $chunk) {
            $done = $this->change->run($store, fn (): array => [$this->applyChunk($store, $chunk, $numbers, $readRef), []]);
            array_push($changed, ...$done);
        }

        return new ProviderStockResult(true, $changed, $archived, $notFound, $invalid);
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

        $switchedOn = array_flip($this->catalog->switchedOnVariantIds($store));
        $sizes = array_values(array_unique([...$this->stock->variantIds($store), ...array_keys($switchedOn)]));
        sort($sizes, SORT_STRING);
        $run = strtolower((string) Str::ulid());
        $stops = 0;

        foreach (array_chunk($sizes, self::CHUNK) as $chunk) {
            $stops += $this->change->run($store, function () use ($store, $chunk, $switchedOn, $run): array {
                $rows = $this->stock->lockExisting($store, $chunk);
                $stops = 0;

                foreach ($chunk as $variantId) {
                    $row = $rows[$variantId] ?? null;
                    $stops += isset($switchedOn[$variantId]) && self::stops($row) ? 1 : 0;

                    // The pieces returned while the provider ruled are ours now (§1.9; sync.md §9.2 #12).
                    if ($row !== null && $row->returnedExtra > 0) {
                        $after = $row->inStock + $row->returnedExtra;
                        $this->movements->record($store, $variantId, MovementReason::Returned, $row->inStock, $after, null, "unwired:{$run}:{$variantId}");
                        $this->stock->setInStock($store, $variantId, $after);
                        $this->stock->setReturnedExtra($store, $variantId, 0);
                    }
                }

                // Every size told again, a size with no row too: the store has no provider now.
                $this->facts->refresh($store, $chunk, all: true);

                return [$stops, []];
            });
        }

        return $stops;
    }

    /**
     * @param  array<string, array{string, string}>  $chunk  own id => [the id as given, its product's id]
     * @param  array<string, int>  $numbers  id as given => the provider's number
     * @return list<string> the sizes changed, by the id as given
     */
    private function applyChunk(StoreId $store, array $chunk, array $numbers, string $readRef): array
    {
        $rows = $this->stock->lockFor($store, array_map(static fn (array $size): string => $size[1], $chunk));
        $changed = $told = [];

        foreach ($chunk as $variantId => [$id]) {
            $row = $rows[$variantId];
            $number = $numbers[$id];
            $reference = "provider:{$readRef}:{$variantId}";

            // The same number changes nothing; a read run again after a later one finds its own movement.
            if ($row->inStock === $number || $this->movements->referenced($reference)) {
                continue;
            }

            $this->movements->record($store, $variantId, MovementReason::Provider, $row->inStock, $number, null, $reference);
            $this->stock->setInStock($store, $variantId, $number);

            // The provider's number changed: we follow it, and what was returned meanwhile goes (§1.9).
            if ($row->returnedExtra !== 0) {
                $this->stock->setReturnedExtra($store, $variantId, 0);
            }

            if ($number < $row->inStock + $row->returnedExtra && $number < $row->held) {
                $this->notice->send($store, $variantId);
            }

            $changed[] = $id;
            $told[] = $variantId;
        }

        $this->facts->refresh($store, $told);

        return $changed;
    }

    /** Whether a size orderable in the wired store stops once it is not: every product then counts on its stock. */
    private static function stops(?StockRow $row): bool
    {
        $after = $row === null ? null : new StockRow($row->variantId, $row->productId, $row->inStock + $row->returnedExtra, $row->held, $row->threshold, $row->stockDependent);

        return StockFigures::orderable($row, wired: true) && ! StockFigures::orderable($after, wired: false);
    }
}
