<?php

declare(strict_types=1);

namespace Modules\Shipping\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Shipping\Application\Uncovered\UncoveredCounts;
use Modules\Shipping\Application\Uncovered\UncoveredPlaces;
use Shared\Domain\ValueObject\StoreId;

/**
 * A store's "Places no carrier serves" count, counted again (shipping.md §1.6); every store when none
 * is named - every ten minutes, for what Platform changes without telling Shipping.
 */
final class CountUncoveredPlacesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Three tries (platform.md §3: every queued job states its own); the next ten minutes mend a miss. */
    public int $tries = 3;

    public function __construct(
        public readonly ?string $storeId = null,
    ) {}

    public function handle(PlatformApi $platform, UncoveredPlaces $places, UncoveredCounts $counts): void
    {
        $stores = $this->storeId === null
            ? array_map(static fn ($store): StoreId => $store->storeId(), $platform->allStores())
            : [StoreId::fromString($this->storeId)];

        foreach ($stores as $store) {
            $counts->write($store->value, $places->count($store));
        }
    }
}
