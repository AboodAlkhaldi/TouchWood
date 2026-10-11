<?php

declare(strict_types=1);

namespace Modules\Inventory\Application;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Inventory\Application\Stock\StoreWiring;
use Modules\Inventory\Domain\Exception\AlreadyHeld;
use Modules\Inventory\Domain\Exception\HoldNotEditable;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\NoHold;
use Modules\Inventory\Domain\Exception\NotEnoughStock;
use Modules\Inventory\Domain\Exception\NotWired;
use Modules\Inventory\Domain\Exception\QuantityInvalid;
use Modules\Inventory\Domain\Exception\ShipMoreThanHeld;
use Modules\Inventory\Domain\Exception\StockBelowZero;
use Modules\Inventory\Domain\Repository\Holds;
use Modules\Inventory\Domain\Repository\StockMovements;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Inventory\Domain\Repository\StoreProducts;
use Modules\Inventory\Domain\ValueObject\Hold;
use Modules\Inventory\Domain\ValueObject\HoldLine;
use Modules\Inventory\Domain\ValueObject\MovementReason;
use Modules\Inventory\Domain\ValueObject\StockLimits;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Inventory\Public\Dto\StockDto;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * `InventoryApi` (inventory.md §1.3, §1.4, §1.9, §2.1, §4.1). Module to module: no permission is
 * checked. Every change runs in its own transaction, retried on a deadlock; sizes are touched in
 * size-id order, as every writer to the stock does (`StockRows`). A hold is one conditional update per
 * size, all or nothing. Shipping, ticking, freeing, editing and returning are safe to repeat.
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
            $free = $row === null ? 0 : max(0, $row->inStock + ($wired ? $row->returnedExtra : 0) - $row->held);
            // A store with no provider counts every product; a wired one only its stock-dependent sizes
            // (§1.1 rule 3). A gift counts in every store, so its figure is always a number.
            $counts = ! $wired || ($row !== null && $row->stockDependent);
            $endingSoon = $counts && $row !== null && $free >= 1 && $free <= ($row->threshold ?? $default)
                && ($wired || isset($switchedOn[$row->productId]));

            $answer[$id] = new StockDto($id, $counts, ! $counts || $free > 0, $counts ? $free : null, $free, $endingSoon);
        }

        return $answer;
    }

    public function hold(StoreId $store, string $orderId, array $lines, ?DateTimeImmutable $expiresAt): void
    {
        $this->platform->store($store) ?? throw new InvalidInventoryInput('store');
        $orderId = self::order($orderId);
        $wanted = $this->lines($lines);

        $this->db->transaction(function () use ($store, $orderId, $wanted, $expiresAt): void {
            if ($this->holds->lockForOrder($orderId) !== null) {
                throw new AlreadyHeld;
            }

            $wired = $this->wiring->isWired($store);
            $rows = $this->stock->read($store, array_values(array_unique(array_map(static fn (HoldLineDto $line): string => $line->variantId, $wanted))));
            $lines = [];

            foreach ($wanted as $line) {
                $holdLine = new HoldLine($line->variantId, $line->gift, $line->quantity, self::counts($wired, $line->gift, $rows[$line->variantId]->stockDependent ?? false));
                $lines[$holdLine->key()] = $holdLine;
            }

            $this->reserve($store, $wired, self::heldBySize($lines, $wired));
            $this->holds->create(new Hold(strtolower((string) Str::ulid()), $store->value, $orderId, $expiresAt, $lines));
        }, 3);
    }

    public function ship(string $orderId, array $lines): void
    {
        $shipped = $this->lines($lines, checkSizes: false);

        $this->db->transaction(function () use ($orderId, $shipped): void {
            [$hold, $store, $wired] = $this->held($orderId);

            foreach ($shipped as $line) {
                $current = $hold->lines[HoldLine::keyOf($line->variantId, $line->gift)] ?? throw new InvalidInventoryInput('variant');

                // A line shipped, ticked or freed already is left as it is.
                if ($current->ended !== HoldLine::OPEN) {
                    continue;
                }

                if ($line->quantity > $current->quantity - $current->shipped) {
                    throw new ShipMoreThanHeld;
                }

                if (! $wired && $current->countsOnStock) {
                    $before = $this->stock->lockExisting($store, [$line->variantId])[$line->variantId]->inStock ?? 0;

                    if ($before < $line->quantity) {
                        throw new StockBelowZero;
                    }

                    $this->stock->take($store, $line->variantId, $line->quantity);
                    $after = $current->shipped + $line->quantity;
                    $this->movements->record($store, $line->variantId, MovementReason::Shipped, $before, $before - $line->quantity, null, "ship:{$hold->orderId}:{$current->key()}:{$after}");
                }

                $shippedNow = $current->shipped + $line->quantity;
                // In a wired store a counting line's hold waits for the tick, whatever was shipped.
                $done = $shippedNow === $current->quantity && (! $wired || ! $current->countsOnStock);
                $this->holds->saveLine($hold->id, new HoldLine($current->variantId, $current->gift, $current->quantity, $current->countsOnStock, $shippedNow, $done ? HoldLine::SHIPPED : HoldLine::OPEN));
            }
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
            $more = [];

            foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $variantId) {
                $change = ($after[$variantId] ?? 0) - ($before[$variantId] ?? 0);

                if ($change > 0) {
                    $more[$variantId] = $change;
                } elseif ($change < 0) {
                    $this->stock->free($store, $variantId, -$change);
                }
            }

            // The extra pieces held as at placement: one line short and nothing changes.
            $this->reserve($store, $wired, $more);

            foreach ($new as $line) {
                $this->holds->saveLine($hold->id, $line);
            }

            foreach ($old as $key => $line) {
                if (! isset($new[$key])) {
                    $this->holds->saveLine($hold->id, new HoldLine($line->variantId, $line->gift, $line->quantity, $line->countsOnStock, 0, HoldLine::RELEASED));
                }
            }
        }, 3);
    }

    public function returned(string $orderId, string $returnId, array $lines): void
    {
        $back = $this->lines($lines);
        $variants = $this->catalog->variants(array_map(static fn (HoldLineDto $line): string => $line->variantId, $back));

        $this->db->transaction(function () use ($orderId, $returnId, $back, $variants): void {
            [$hold, $store, $wired] = $this->held($orderId);

            foreach ($back as $line) {
                // Each return counted once (§2.1).
                if ($this->holds->returnCounted($returnId, $line->variantId, $line->gift)) {
                    continue;
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
        }, 3);
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
        $store = StoreId::fromString($hold->storeId);

        return [$hold, $store, $this->wiring->isWired($store)];
    }

    /**
     * Holds each size's pieces, in size-id order; every short size is named, and nothing is held.
     *
     * @param  array<string, int>  $pieces  variant id => pieces
     */
    private function reserve(StoreId $store, bool $wired, array $pieces): void
    {
        ksort($pieces);
        $short = [];

        foreach ($pieces as $variantId => $count) {
            if (! $this->stock->reserve($store, (string) $variantId, $count, $wired)) {
                $short[(string) $variantId] = $count;
            }
        }

        if ($short !== []) {
            $rows = $this->stock->read($store, array_map('strval', array_keys($short)));
            $available = [];

            foreach (array_keys($short) as $variantId) {
                $row = $rows[$variantId] ?? null;
                $available[$variantId] = $row === null ? 0 : max(0, $row->inStock + ($wired ? $row->returnedExtra : 0) - $row->held);
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
