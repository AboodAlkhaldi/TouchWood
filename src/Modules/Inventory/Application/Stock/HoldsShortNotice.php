<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Modules\Inventory\Domain\Repository\Holds;
use Modules\Inventory\Public\Events\HoldsShort;
use Shared\Domain\ValueObject\StoreId;

/**
 * Tells Sales, after the change commits, which orders hold a size whose in stock a hand change took
 * below what is held (inventory.md §1.5, §6.1): every one of them - none is blocked; staff sort it out
 * (owner, 2026-10-11). And says to the change's own answer which sizes it left short (stage 5's screens
 * step 4): the screen's amber toast after the save.
 */
final readonly class HoldsShortNotice
{
    public function __construct(
        private Holds $holds,
        private Dispatcher $events,
    ) {}

    /**
     * @return list<string> the orders holding the size, each named in the event
     */
    public function send(StoreId $store, string $variantId): array
    {
        $orders = $this->holds->ordersHolding($store, $variantId);

        if ($orders !== []) {
            $this->events->dispatch(new HoldsShort((string) Str::uuid(), $store->value, $variantId, $orders, CarbonImmutable::now()->toDateTimeImmutable()));
        }

        return $orders;
    }

    /**
     * A size a change left with in stock below what is held: named for the change's answer, and - when
     * the change took it down (§6.1: a removal or a stocktake that lowered it) - its orders told to Sales.
     * A size the change raised but left short still is named, with no event: nothing new to flag.
     */
    public function short(StoreId $store, string $variantId, int $before, int $after, int $held): ?ShortSize
    {
        if ($after >= $held) {
            return null;
        }

        $orders = $after < $before ? $this->send($store, $variantId) : $this->holds->ordersHolding($store, $variantId);

        return new ShortSize($variantId, count($orders), $held - $after);
    }
}
