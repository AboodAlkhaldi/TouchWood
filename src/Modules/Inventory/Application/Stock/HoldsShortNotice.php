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
 * (owner, 2026-10-11).
 */
final readonly class HoldsShortNotice
{
    public function __construct(
        private Holds $holds,
        private Dispatcher $events,
    ) {}

    public function send(StoreId $store, string $variantId): void
    {
        $orders = $this->holds->ordersHolding($store, $variantId);

        if ($orders !== []) {
            $this->events->dispatch(new HoldsShort((string) Str::uuid(), $store->value, $variantId, $orders, CarbonImmutable::now()->toDateTimeImmutable()));
        }
    }
}
