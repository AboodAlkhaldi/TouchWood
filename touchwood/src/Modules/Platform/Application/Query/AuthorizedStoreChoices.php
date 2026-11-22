<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query;

use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * A screen's store filter, from the permissions behind it (platform.md §9.10).
 *
 * Asked of the Authorizer once per permission (storesWith), never store by store, and read from the
 * cached directory, so a filter costs nothing per store.
 */
final readonly class AuthorizedStoreChoices implements StoreChoices
{
    public function __construct(
        private Authorizer $authorizer,
        private StoreDirectory $directory,
    ) {}

    public function forJobs(string ...$permissions): array
    {
        $stores = $this->directory->stores();

        // Whoever may turn a store back on - a Super Admin - prepares an off store before it opens
        // (§1.6): every store is offered, as the handlers let them in (UpdateStore, UpdateSetting).
        if ($this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) !== []) {
            return $stores;
        }

        $held = [];

        foreach ($permissions as $permission) {
            $reach = $this->authorizer->storesWith($permission);

            // Every store, now and later: the stores that are on, all of them.
            if ($reach === null) {
                return array_values(array_filter($stores, static fn (StoreDto $store): bool => $store->isActive));
            }

            foreach ($reach as $store) {
                $held[$store->value] = true;
            }
        }

        return array_values(array_filter($stores, static fn (StoreDto $store): bool => $store->isActive && isset($held[$store->id])));
    }

    public function chosen(?string $code, string ...$permissions): ?StoreDto
    {
        $offered = $this->forJobs(...$permissions);

        // None asked: the first that is on. Nobody lands in an off store without choosing it, a
        // Super Admin included, even when it comes first (the review of the foundation, 2026-10-03).
        if ($code === null || trim($code) === '') {
            foreach ($offered as $store) {
                if ($store->isActive) {
                    return $store;
                }
            }

            return null;
        }

        $asked = strtolower(trim($code));

        foreach ($offered as $store) {
            if ($store->code === $asked) {
                return $store;
            }
        }

        // Another store, or one that does not exist: the same answer, so a filter tells nobody which
        // stores there are.
        throw new Unauthorized($permissions[0] ?? 'store');
    }

    public function baseTimezone(): string
    {
        foreach ($this->directory->stores() as $store) {
            if ($store->isBase) {
                return $store->timezone;
            }
        }

        // Before any store is marked base - an empty installation - the first store's, else UTC.
        return $this->directory->stores()[0]->timezone ?? 'UTC';
    }
}
