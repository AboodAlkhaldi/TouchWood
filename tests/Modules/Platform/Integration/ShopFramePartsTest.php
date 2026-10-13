<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Platform\Application\Routing\InMemoryShopFrameParts;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\ShopFramePart;
use Modules\Platform\Public\Contracts\ShopFrameParts;
use Modules\Platform\Public\Dto\StoreDto;

use function Pest\Laravel\seed;

/*
| The shop frame's parts (catalog.md §4.5; platform.md §9.4, 2026-10-12): what modules put in every
| shop page's frame, registered in their providers, carried by module; Platform never reads what one
| holds. Catalog's - the menu and the search box - is checked in its ShopPagesTest.
*/

uses(RefreshDatabase::class);

final class ShopFramePartsTestPart implements ShopFramePart
{
    public function partFor(StoreDto $store, string $locale): array
    {
        return ['store' => $store->code, 'locale' => $locale];
    }
}

it('carries each registered part by its module, asked for this store and language', function () {
    seed(PlatformSeeder::class);
    $parts = new InMemoryShopFrameParts(app());
    $parts->register('one', ShopFramePartsTestPart::class);
    $store = app(PlatformApi::class)->allStores()[0];

    expect($parts->for($store, 'ar'))->toBe(['one' => ['store' => $store->code, 'locale' => 'ar']])
        ->and((new InMemoryShopFrameParts(app()))->for($store, 'en'))->toBe([]);
});

it('refuses a class that is not a part, and a module registering twice', function () {
    $parts = new InMemoryShopFrameParts(app());
    $parts->register('one', ShopFramePartsTestPart::class);

    expect(fn () => $parts->register('two', stdClass::class))->toThrow(LogicException::class, 'does not implement')
        ->and(fn () => $parts->register('one', ShopFramePartsTestPart::class))->toThrow(LogicException::class, 'already has a part');
});

it('is one list for the whole application', function () {
    expect(app(ShopFrameParts::class))->toBe(app(InMemoryShopFrameParts::class));
});
