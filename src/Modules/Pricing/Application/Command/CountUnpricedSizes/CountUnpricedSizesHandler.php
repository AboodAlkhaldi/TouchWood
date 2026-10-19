<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\CountUnpricedSizes;

use Carbon\CarbonImmutable;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Pricing\Application\Prices\UnpricedSizes;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Repository\UnpricedCounts;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The Needs a Price count kept per store** (owner Q43, 2026-10-19; pricing.md §1.9), as Inventory's Low
 * Stock count: the store's sizes that need a price counted with the list's own reader - so the count and
 * the list never disagree - and kept for the menu. Queued after every change that can move it, and each
 * night for every store. Changes nothing a person did, so it audits nothing; answers how many stores were
 * counted.
 */
final readonly class CountUnpricedSizesHandler
{
    /** Reserved: the system's. */
    public const string PERMISSION = PricingPermissions::NEEDS_A_PRICE_COUNT;

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private UnpricedSizes $unpriced,
        private UnpricedCounts $counts,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(CountUnpricedSizes $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $stores = $command->storeId === null
            ? array_map(static fn ($store): StoreId => $store->storeId(), $this->platform->allStores())
            : [StoreId::fromString($command->storeId)];

        // Started before counting: a change made while it counts queues another, never lost.
        if ($command->storeId === null) {
            $this->counts->allRecountsStart();
        } else {
            $this->counts->recountStarts($stores[0]);
        }

        foreach ($stores as $store) {
            // A store gone since the job was queued has nothing to count.
            if ($this->platform->store($store) !== null) {
                // Its start, not its end: a count started later, and kept first, is the newer one.
                $startedAt = CarbonImmutable::now()->toDateTimeImmutable();
                $this->counts->save($store, $this->unpriced->count($store), $startedAt);
            }
        }

        return count($stores);
    }
}
