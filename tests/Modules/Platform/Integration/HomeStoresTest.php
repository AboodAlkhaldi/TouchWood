<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Public\Contracts\HomeStores;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| The stores a home card speaks for (dashboard.md §3.2, D8): the one chosen, or every store for All Stores - the base
| store first, an off one only for whoever may switch stores - and none outside the reader's reach.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/** @return list<string>|null */
function homeStoresCodes(HomeScope $scope, string $permission = PlatformPermissions::SETTINGS_VIEW): ?array
{
    $stores = app(HomeStores::class)->for($scope, $permission);

    return $stores === null ? null : array_map(static fn (StoreDto $store): string => $store->code, $stores);
}

it('puts the base store first for All Stores, then the stores by position', function () {
    DB::table('platform.stores')->update(['is_base' => false]);
    DB::table('platform.stores')->where('code', 'eg')->update(['is_base' => true]);
    app(StoreDirectory::class)->invalidate();
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    expect(homeStoresCodes(HomeScope::allStores()))->toBe(['eg', 'sa', 'ae']);
});

it('lists an off store for whoever may switch stores, and leaves it out for anyone else', function () {
    DB::table('platform.stores')->where('code', 'ae')->update(['is_active' => false]);
    app(StoreDirectory::class)->invalidate();

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    expect(homeStoresCodes(HomeScope::allStores()))->toBe(['sa', 'eg', 'ae']);

    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['*']));
    expect(homeStoresCodes(HomeScope::allStores()))->toBe(['sa', 'eg']);
});

it('answers one store only where the reader holds the permission, and All Stores only for a reader holding it everywhere', function () {
    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa', 'eg']));

    expect(homeStoresCodes(HomeScope::store(StoreId::fromString(Fx::storeId('eg')))))->toBe(['eg'])
        ->and(homeStoresCodes(HomeScope::store(StoreId::fromString(Fx::storeId('ae')))))->toBeNull()
        ->and(homeStoresCodes(HomeScope::allStores()))->toBeNull()
        // Held, but not this one.
        ->and(homeStoresCodes(HomeScope::store(StoreId::fromString(Fx::storeId('sa'))), PlatformPermissions::STORE_UPDATE))->toBeNull();
});
