<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Presentation\Home\StoresAndSystemCard;
use Modules\Platform\Public\Dto\HomeFigure;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/*
| Platform's card on the admin home, Stores and System (platform.md §9.8): each figure only with its
| own permission; stores off only to whoever may switch stores, as the stores screen lists them.
*/

/**
 * @return list<string>|null the card's figures by label, or null when it shows nothing
 */
function storesAndSystemFigures(HomeScope $scope): ?array
{
    $data = app(StoresAndSystemCard::class)->data($scope);

    return $data === null ? null : array_map(static fn (HomeFigure $figure): string => $figure->label, $data->figures);
}

it('shows a Super Admin every figure in All Stores, and the store-free ones in This Store', function () {
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    expect(storesAndSystemFigures(HomeScope::allStores()))->toBe(['stores_on', 'stores_off', 'failed_jobs', 'storage'])
        ->and(storesAndSystemFigures(HomeScope::store(StoreId::fromString(Fx::storeId('sa')))))->toBe(['failed_jobs', 'storage']);
});

it('counts no off store for a reader of every store who may not switch stores', function () {
    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_VIEW], ['*']));

    expect(storesAndSystemFigures(HomeScope::allStores()))->toBe(['stores_on'])
        // Stores on and off is an all-stores figure; nothing else is theirs.
        ->and(storesAndSystemFigures(HomeScope::store(StoreId::fromString(Fx::storeId('sa')))))->toBeNull();
});

it('shows each store-free figure only with its own permission', function (array $permissions, RoleLevel $level, ?array $figures) {
    Fx::actAsStaff(Fx::staffWith(Fx::names($permissions), ['sa'], $level));

    expect(storesAndSystemFigures(HomeScope::store(StoreId::fromString(Fx::storeId('sa')))))->toBe($figures);
})->with([
    'failed jobs' => [[PlatformPermissions::JOBS_MANAGE], RoleLevel::Admin, ['failed_jobs']],
    'storage, by any media action' => [[PlatformPermissions::MEDIA_DELETE], RoleLevel::Staff, ['storage']],
    'stores on, for one store' => [[PlatformPermissions::STORE_VIEW], RoleLevel::Staff, null],
]);
