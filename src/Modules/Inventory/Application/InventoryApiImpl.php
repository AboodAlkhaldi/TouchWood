<?php

declare(strict_types=1);

namespace Modules\Inventory\Application;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Inventory\Application\Stock\InventoryChange;
use Modules\Inventory\Application\Stock\SizeInput;
use Modules\Inventory\Application\Stock\StockDependentSwitch;
use Modules\Inventory\Application\Stock\StockFacts;
use Modules\Inventory\Domain\Exception\AlreadyHeld;
use Modules\Inventory\Domain\Exception\HoldNotEditable;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\NoHold;
use Modules\Inventory\Domain\Exception\NotEnoughStock;
use Modules\Inventory\Domain\Exception\NotWired;
use Modules\Inventory\Domain\Exception\QuantityInvalid;
use Modules\Inventory\Domain\Exception\ShipMoreThanHeld;
use Modules\Inventory\Domain\Repository\Holds;
use Modules\Inventory\Domain\Repository\StockMovements;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Inventory\Domain\Repository\StoreProducts;
use Modules\Inventory\Domain\Service\StockFigures;
use Modules\Inventory\Domain\ValueObject\Hold;
use Modules\Inventory\Domain\ValueObject\HoldLine;
use Modules\Inventory\Domain\ValueObject\MovementReason;
use Modules\Inventory\Domain\ValueObject\StockLimits;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Inventory\Public\Dto\StockDto;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * `InventoryApi` (inventory.md §1.3, §1.4, §1.9, §2.1, §4.1). Module to module: no permission is
 * checked. Every change runs in its own transaction, retried on a deadlock - a savepoint in the
 * caller's when it has one open; sizes are touched in size-id order, as every writer to the stock does
 * (`StockRows`). A hold is one conditional update per size, all or nothing. Shipping, ticking, freeing,
 * editing and returning are safe to repeat.
 */
final readonly class InventoryApiImpl implements InventoryApi
{
    public function __construct(
        private PlatformApi $platform,
        private CatalogApi $catalog,
        private StoreWiring $wiring,
        private StockRows $stock,
        private StoreProducts $products,
        private Holds $holds,
        private StockMovements $movements,
        private ConnectionInterface $db,
        private StockFacts $facts,
        private SizeInput $sizes,
        private InventoryChange $change,
        private StockDependentSwitch $switch,
    ) {}

    public function stock(StoreId $store, array $variantIds): array
    {
        $this->platform->store($store) ?? throw new InvalidInventoryInput('store');
        $wired = $this->wiring->isWired($store);
        $ids = array_values(array_unique(array_map(static fn (string $id): string => strtolower(trim($id)), $variantIds)));
        $rows = $this->stock->read($store, $ids);
        $default = (int) $this->platform->setting(InventorySettings::LOW_STOCK_DEFAULT, $store)->value;
        $switchedOn = $wired ? [] : array_flip($this->products->endingSoonOf($store, array_values(array_unique(array_map(static fn ($row): string => $row->productId, $rows)))));
        $answer = [];

        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            // One place for what stock means (StockFigures), so stock() and Catalog never disagree. A
            // gift counts on stock in every store, so its figure is always a number.
            $free = StockFigures::free($row, $wired);
            $counts = StockFigures::counts($row, $wired);
            $endingSoon = StockFigures::endingSoon($row, $wired, $default, $row !== null && isset($switchedOn[$row->productId]));

            $answer[$id] = new StockDto($id, $counts, StockFigures::orderable($row, $wired), $counts ? $free : null, $free, $endingSoon);
        }

        return $answer;
    }

    public function hold(StoreId $store, string $orderId, array $lines, ?DateTimeImmutable $expiresAt): void
    {
        $this->platform->store($store) ?? throw new InvalidInventoryInput('store');
        $orderId = self::order($orderId);
        $wanted = $this->lines($lines);

        $this->db->transaction(function () use ($store, $orderId, $wanted, $expiresAt): void {
            $wired = $this->wiring->isWired($store);
            $rows = $this->stock->read($store, array_values(array_unique(array_map(static fn (HoldLineDto $line): string => $line->variantId, $wanted))));
            $lines = [];

            foreach ($wanted as $line) {
                $holdLine = new HoldLine($line->variantId, $line->gift, $line->quantity, self::counts($wired, $line->gift, $rows[$line->variantId]->stockDependent ?? false));
                $lines[$holdLine->key()] = $holdLine;
            }

            // The hold first: a second one for the order, even at the same moment, finds it.
            if (! $this->holds->create(new Hold(strtolower((string) Str::ulid()), $store->value, $orderId, $expiresAt, $lines, $wired))) {
                throw new AlreadyHeld;
            }

            $this->apply($store, $wired, self::heldBySize($lines, $wired));
            $this->facts->refresh($store, self::sizes($lines));
        }, 3);
    }

    public function ship(string $orderId, array $lines): void
    {
        $shipped = $this->lines($lines, checkSizes: false);

        $this->db->transaction(function () use ($orderId, $shipped): void {
            [$hold, $store, $wired] = $this->held($orderId);

            foreach (self::sorted($shipped) as $line) {
                $current = $hold->lines[HoldLine::keyOf($line->variantId, $line->gift)] ?? throw new InvalidInventoryInput('variant');

                // A line shipped in full, ticked or freed already is left as it is - in a wired store a
                // line shipped in full stays open until its tick.
                if ($current->ended !== HoldLine::OPEN || $current->shipped === $current->quantity) {
                    continue;
                }

                if ($line->quantity > $current->quantity - $current->shipped) {
                    throw new ShipMoreThanHeld;
                }

                if (! $wired && $current->countsOnStock) {
                    $before = $this->stock->lockExisting($store, [$line->variantId])[$line->variantId]->inStock ?? 0;
                    // Never refused, never below 0 (sales.md answer 41; inventory.md §9.2 #22): what there is
                    // goes, and the pieces shipped beyond the count are named for a recount. The hold lets
                    // go of all of them.
                    $taken = min($before, $line->quantity);
                    $short = $line->quantity - $taken;

                    if ($taken > 0) {
                        $this->stock->take($store, $line->variantId, $taken);
                        $after = $current->shipped + $line->quantity;
                        $this->movements->record($store, $line->variantId, MovementReason::Shipped, $before, $before - $taken, $short > 0 ? self::shortNote($short) : null, "ship:{$hold->orderId}:{$current->key()}:{$after}");
                    }

                    if ($short > 0) {
                        $this->stock->free($store, $line->variantId, $short);
                    }
                }

                $shippedNow = $current->shipped + $line->quantity;
                // In a wired store a counting line's hold waits for the tick, whatever was shipped.
                $done = $shippedNow === $current->quantity && (! $wired || ! $current->countsOnStock);
                $this->holds->saveLine($hold->id, new HoldLine($current->variantId, $current->gift, $current->quantity, $current->countsOnStock, $shippedNow, $done ? HoldLine::SHIPPED : HoldLine::OPEN));
            }

            $this->facts->refresh($store, array_values(array_unique(array_map(static fn (HoldLineDto $line): string => $line->variantId, $shipped))));
        }, 3);
    }

    public function reducedInProvider(string $orderId, array $variantIds): void
    {
        $this->db->transaction(function () use ($orderId, $variantIds): void {
            [$hold, $store, $wired] = $this->held($orderId);

            if (! $wired) {
                throw new NotWired;
            }

            $sizes = array_flip(array_map(static fn (string $id): string => strtolower(trim($id)), $variantIds));

            // Both lines of a size end: staff reduce the provider by the size (§2.1).
            foreach ($hold->lines as $line) {
                if (isset($sizes[$line->variantId]) && $line->countsOnStock && $line->ended === HoldLine::OPEN) {
                    $this->end($hold, $store, $wired, $line, HoldLine::REDUCED);
                }
            }

            $this->facts->refresh($store, self::sizes($hold->lines));
        }, 3);
    }

    public function release(string $orderId): void
    {
        $this->endOpen($orderId, HoldLine::RELEASED);
    }

    /**
     * Frees an expired hold (`ReleaseExpiredHolds`): its open lines end as EXPIRED.
     */
    public function expire(string $orderId): void
    {
        $this->endOpen($orderId, HoldLine::EXPIRED);
    }

    public function adjustHold(string $orderId, array $newLines): void
    {
        $wanted = $this->lines($newLines);

        $this->db->transaction(function () use ($orderId, $wanted): void {
            [$hold, $store, $wired] = $this->held($orderId);

            // Only while held (§4.1): never once cancelled or expired, nor once a line is shipped or ticked.
            if ($hold->ended) {
                throw new HoldNotEditable;
            }

            foreach ($hold->lines as $line) {
                if ($line->shipped > 0 || in_array($line->ended, [HoldLine::SHIPPED, HoldLine::REDUCED], true)) {
                    throw new HoldNotEditable;
                }
            }

            $rows = $this->stock->read($store, array_values(array_unique(array_map(static fn (HoldLineDto $line): string => $line->variantId, $wanted))));
            $old = array_filter($hold->lines, static fn (HoldLine $line): bool => $line->ended === HoldLine::OPEN);
            $new = [];

            foreach ($wanted as $line) {
                $holdLine = new HoldLine($line->variantId, $line->gift, $line->quantity, self::counts($wired, $line->gift, $rows[$line->variantId]->stockDependent ?? false));
                $new[$holdLine->key()] = $holdLine;
            }

            $before = self::heldBySize($old, $wired);
            $after = self::heldBySize($new, $wired);
            $changes = [];

            foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $variantId) {
                $changes[$variantId] = ($after[$variantId] ?? 0) - ($before[$variantId] ?? 0);
            }

            // The extra pieces held as at placement, the fewer freed, in one pass in size-id order: one
            // line short and nothing changes.
            $this->apply($store, $wired, $changes);

            foreach ($new as $line) {
                $this->holds->saveLine($hold->id, $line);
            }

            foreach ($old as $key => $line) {
                if (! isset($new[$key])) {
                    $this->holds->saveLine($hold->id, new HoldLine($line->variantId, $line->gift, $line->quantity, $line->countsOnStock, 0, HoldLine::RELEASED));
                }
            }

            $this->facts->refresh($store, array_map('strval', array_keys($changes)));
        }, 3);
    }

    public function returned(string $orderId, string $returnId, array $lines): void
    {
        $returnId = trim($returnId);

        if ($returnId === '' || mb_strlen($returnId) > 64) {
            throw new InvalidInventoryInput('return');
        }

        $back = $this->lines($lines);
        $variants = $this->catalog->variants(array_map(static fn (HoldLineDto $line): string => $line->variantId, $back));

        $this->db->transaction(function () use ($orderId, $returnId, $back, $variants): void {
            [$hold, $store, $wired] = $this->held($orderId);

            foreach (self::sorted($back) as $line) {
                // Each return counted once (§2.1).
                if ($this->holds->returnCounted($returnId, $line->variantId, $line->gift)) {
                    continue;
                }

                // Only the order's own line, and never more pieces back than went out: shipped from a
                // store with no provider; the line's pieces in a wired one.
                $held = $hold->lines[HoldLine::keyOf($line->variantId, $line->gift)] ?? throw new InvalidInventoryInput('variant');

                if ($this->holds->returnedSoFar($hold->orderId, $line->variantId, $line->gift) + $line->quantity > ($wired ? $held->quantity : $held->shipped)) {
                    throw new QuantityInvalid;
                }

                $this->holds->recordReturn($returnId, $line->variantId, $line->gift, $store, $hold->orderId, $line->quantity);
                $productId = $variants[$line->variantId]->productId;

                if (! $wired) {
                    $before = $this->stock->lockFor($store, [$line->variantId => $productId])[$line->variantId]->inStock;

                    if ($before + $line->quantity > StockLimits::MOST_PIECES) {
                        throw new QuantityInvalid;
                    }

                    $this->stock->setInStock($store, $line->variantId, $before + $line->quantity);
                    $this->movements->record($store, $line->variantId, MovementReason::Returned, $before, $before + $line->quantity, null, "return:{$returnId}:".HoldLine::keyOf($line->variantId, $line->gift));

                    continue;
                }

                // A wired store: an ordinary product follows the provider's number; a stock-dependent
                // size or a gift is added on our side until that number next changes (§1.9, provisional).
                $stockDependent = $this->stock->read($store, [$line->variantId])[$line->variantId]->stockDependent ?? false;

                if ($line->gift || $stockDependent) {
                    $this->stock->addReturnedExtra($store, $line->variantId, $productId, $line->quantity);
                }
            }

            $this->facts->refresh($store, array_values(array_unique(array_map(static fn (HoldLineDto $line): string => $line->variantId, $back))));
        }, 3);
    }

    public function makeStockDependent(StoreId $store, array $variantIds): array
    {
        $this->platform->store($store) ?? throw new InvalidInventoryInput('store');

        // Only a wired store has the switch: in one with no provider every size counts on its stock already.
        if ($variantIds === [] || ! $this->wiring->isWired($store)) {
            return [];
        }

        $products = [];

        // As staff's switch: every size known and not archived, or nothing changes (SizeInput).
        foreach ($this->sizes->check($variantIds) as [$variantId, $productId]) {
            $products[$variantId] = $productId;
        }

        // The caller's transaction when there is one (a savepoint in it), its own otherwise: the store's
        // lock first, the audit entries inside (InventoryChange). On only, never off.
        return $this->change->run($store, fn (): array => $this->switch->set($store, $products, true));
    }

    /**
     * The ledger's note on a shipment that took the stock to 0 with pieces still to go (sales.md answer 41).
     */
    private static function shortNote(int $short): string
    {
        return "{$short} more shipped than counted - recount";
    }

    private function endOpen(string $orderId, string $ended): void
    {
        $this->db->transaction(function () use ($orderId, $ended): void {
            [$hold, $store, $wired] = $this->held($orderId);

            foreach ($hold->lines as $line) {
                if ($line->ended === HoldLine::OPEN) {
                    $this->end($hold, $store, $wired, $line, $ended);
                }
            }

            $this->holds->markEnded($hold->id);
            $this->facts->refresh($store, self::sizes($hold->lines));
        }, 3);
    }

    private function end(Hold $hold, StoreId $store, bool $wired, HoldLine $line, string $ended): void
    {
        $held = $line->held($wired);

        if ($held > 0) {
            $this->stock->free($store, $line->variantId, $held);
        }

        $this->holds->saveLine($hold->id, new HoldLine($line->variantId, $line->gift, $line->quantity, $line->countsOnStock, $line->shipped, $ended));
    }

    /**
     * @return array{Hold, StoreId, bool}
     */
    private function held(string $orderId): array
    {
        $hold = $this->holds->lockForOrder($orderId) ?? throw new NoHold;

        // The wiring the hold was placed under: what each line holds is worked out by it for good.
        return [$hold, StoreId::fromString($hold->storeId), $hold->wired];
    }

    /**
     * Holds each size's extra pieces and frees its fewer ones, in one pass in size-id order (the
     * order every writer to the stock keeps); every short size is named, and nothing changes.
     *
     * @param  array<string, int>  $changes  variant id => pieces more (above 0) or fewer (below 0)
     */
    private function apply(StoreId $store, bool $wired, array $changes): void
    {
        ksort($changes);
        $short = [];

        foreach ($changes as $variantId => $count) {
            if ($count > 0 && ! $this->stock->reserve($store, (string) $variantId, $count, $wired)) {
                $short[(string) $variantId] = $count;
            } elseif ($count < 0) {
                $this->stock->free($store, (string) $variantId, -$count);
            }
        }

        if ($short !== []) {
            $rows = $this->stock->read($store, array_map('strval', array_keys($short)));
            $available = [];

            foreach (array_keys($short) as $variantId) {
                $available[$variantId] = StockFigures::free($rows[$variantId] ?? null, $wired);
            }

            throw new NotEnoughStock($available);
        }
    }

    /**
     * @param  array<string, HoldLine>  $lines
     * @return array<string, int> variant id => pieces held
     */
    private static function heldBySize(array $lines, bool $wired): array
    {
        $held = [];

        foreach ($lines as $line) {
            if ($line->countsOnStock) {
                $held[$line->variantId] = ($held[$line->variantId] ?? 0) + ($wired ? $line->quantity : $line->quantity - $line->shipped);
            }
        }

        return $held;
    }

    /**
     * @param  list<HoldLineDto>  $lines
     * @return list<HoldLineDto> by size id, then bought before gift: the order rows are locked in
     */
    private static function sorted(array $lines): array
    {
        usort($lines, static fn (HoldLineDto $a, HoldLineDto $b): int => [$a->variantId, $a->gift] <=> [$b->variantId, $b->gift]);

        return $lines;
    }

    /**
     * @param  array<string, HoldLine>  $lines
     * @return list<string> their sizes, once each
     */
    private static function sizes(array $lines): array
    {
        return array_values(array_unique(array_map(static fn (HoldLine $line): string => $line->variantId, $lines)));
    }

    private static function counts(bool $wired, bool $gift, bool $stockDependent): bool
    {
        return ! $wired || $gift || $stockDependent;
    }

    private static function order(string $orderId): string
    {
        $orderId = strtolower(trim($orderId));

        return Str::isUlid($orderId) ? $orderId : throw new InvalidInventoryInput('order');
    }

    /**
     * The lines as given, each size by its own id, a quantity of 1 or more, and each size and gift
     * once; with `checkSizes`, every size known to Catalog.
     *
     * @param  list<HoldLineDto>  $lines
     * @return list<HoldLineDto>
     */
    private function lines(array $lines, bool $checkSizes = true): array
    {
        $seen = [];
        $clean = [];

        foreach ($lines as $line) {
            if ($line->quantity < 1 || $line->quantity > StockLimits::MOST_PIECES) {
                throw new QuantityInvalid;
            }

            $clean[] = $own = new HoldLineDto(strtolower(trim($line->variantId)), $line->quantity, $line->gift);
            $key = HoldLine::keyOf($own->variantId, $own->gift);

            if (isset($seen[$key])) {
                throw new InvalidInventoryInput('variant');
            }

            $seen[$key] = true;
        }

        if ($checkSizes && $clean !== []) {
            $known = $this->catalog->variants(array_values(array_unique(array_map(static fn (HoldLineDto $line): string => $line->variantId, $clean))));

            foreach ($clean as $line) {
                if (! isset($known[$line->variantId])) {
                    throw new InvalidInventoryInput('variant');
                }
            }
        }

        return $clean;
    }
}
