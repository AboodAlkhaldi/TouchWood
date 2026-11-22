<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The stores a reader works in on Pricing's screens, and the jobs held in each** (pricing.md §4.3.1 "Who
 * may do what", Q1): the stores where they hold any of the jobs asked, in the stores' order - a store that
 * is off only for whoever may switch stores, as the reads refuse it to anyone else. A read spanning stores
 * asks the authorizer which stores hold each job, never a store at a time.
 */
final readonly class ReaderStores
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
    ) {}

    /**
     * @param  list<string>  $permissions
     * @return array<string, array{store: StoreDto, jobs: list<string>}> by store id; a store with none of them left out
     */
    public function authorize(array $permissions): array
    {
        $held = [];

        foreach ($permissions as $permission) {
            $stores = $this->authorizer->storesWith($permission);
            $held[$permission] = $stores === null ? null : array_map(static fn (StoreId $store): string => $store->value, $stores);
        }

        $mayOff = $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) !== [];
        $found = [];

        foreach ($this->platform->allStores() as $store) {
            if (! $store->isActive && ! $mayOff) {
                continue;
            }

            $id = strtolower($store->id);
            $jobs = array_values(array_filter($permissions, static fn (string $permission): bool => $held[$permission] === null || in_array($id, $held[$permission], true)));

            if ($jobs !== []) {
                $found[$id] = ['store' => $store, 'jobs' => $jobs];
            }
        }

        return $found;
    }
}
