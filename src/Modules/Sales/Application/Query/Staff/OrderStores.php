<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Staff;

use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * The stores Sales's order and return screens list to a reader (sales.md §1.12): the stores where they hold
 * the permission — an off one only while it holds an unfinished order, an exception to platform.md §9.10 #4
 * for these screens; a Super Admin's offer every off store, as every store filter does.
 */
final readonly class OrderStores
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private StaffReads $reads,
    ) {}

    /**
     * The stores in the platform's order, from the stores where the reader holds the permission (null: every
     * store); asks `storesWith` again only to know a Super Admin.
     *
     * @param  list<StoreId>|null  $covered
     * @return list<StoreDto>
     */
    public function listed(?array $covered): array
    {
        $ids = $covered === null ? null : array_map(static fn (StoreId $store): string => $store->value, $covered);
        $stores = array_values(array_filter($this->platform->allStores(), static fn (StoreDto $store): bool => $ids === null || in_array($store->id, $ids, true)));

        if ($this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) !== []) {
            return $stores;
        }

        $off = array_map(static fn (StoreDto $store): string => $store->id, array_filter($stores, static fn (StoreDto $store): bool => ! $store->isActive));
        $unfinished = $this->reads->storesWithUnfinished(array_values($off));

        return array_values(array_filter($stores, static fn (StoreDto $store): bool => $store->isActive || in_array($store->id, $unfinished, true)));
    }

    /**
     * The ids of those stores; null when they are every store there is (a Super Admin of all stores).
     *
     * @param  list<StoreId>|null  $covered
     * @return list<string>|null
     */
    public function ids(?array $covered): ?array
    {
        $listed = array_map(static fn (StoreDto $store): string => $store->id, $this->listed($covered));

        return $covered === null && count($listed) === count($this->platform->allStores()) ? null : $listed;
    }
}
