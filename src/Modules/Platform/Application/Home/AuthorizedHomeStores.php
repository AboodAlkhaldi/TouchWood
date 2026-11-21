<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Home;

use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Public\Contracts\HomeStores;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * `HomeStores` from the store directory and the Shared `Authorizer` - never Access, which sits above Platform.
 * Bound scoped: the Authorizer answers for the person acting now.
 */
final readonly class AuthorizedHomeStores implements HomeStores
{
    public function __construct(
        private Authorizer $authorizer,
        private StoreDirectory $directory,
    ) {}

    public function for(HomeScope $scope, string $permission): ?array
    {
        $held = $this->authorizer->storesWith($permission);
        $one = $scope->stores();

        if ($one !== null) {
            $store = $this->directory->storeById($one[0]->value);

            return $store === null || ! self::holds($held, $store) ? null : [$store];
        }

        if ($held !== null) {
            return null;
        }

        $mayOff = $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) !== [];
        $stores = array_values(array_filter($this->directory->stores(), static fn (StoreDto $store): bool => $store->isActive || $mayOff));
        usort($stores, static fn (StoreDto $a, StoreDto $b): int => [! $a->isBase, $a->position] <=> [! $b->isBase, $b->position]);

        return $stores;
    }

    /** @param  list<StoreId>|null  $held */
    private static function holds(?array $held, StoreDto $store): bool
    {
        return $held === null || in_array(strtolower($store->id), array_map(static fn (StoreId $one): string => $one->value, $held), true);
    }
}
