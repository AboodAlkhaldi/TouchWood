<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\External;

use Modules\Inventory\Public\Contracts\StoreWiring as InventoryWiring;
use Modules\Pricing\Public\Contracts\StoreWiring as PricingWiring;
use Modules\Sync\Domain\Repository\Connections;
use Shared\Domain\ValueObject\StoreId;

/**
 * Sync's answer to the question Pricing and Inventory ask (sync.md §2.2, §2.3): a store is wired while it
 * has a connection. Read each time, in the caller's transaction, so wiring and unwiring are seen at once.
 */
final readonly class ConnectionWiring implements InventoryWiring, PricingWiring
{
    public function __construct(
        private Connections $connections,
    ) {}

    public function isWired(StoreId $store): bool
    {
        return $this->connections->exists($store);
    }

    /** Pricing's: every wired store at once (pricing.md §4.3.3). */
    public function wiredStores(): array
    {
        return $this->connections->wiredStores();
    }
}
