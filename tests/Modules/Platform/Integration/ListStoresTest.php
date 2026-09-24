<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Platform\Application\Query\ListStores\ListStores;
use Modules\Platform\Application\Query\ListStores\ListStoresHandler;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @return list<string>
 */
function listedStoreCodes(): array
{
    return array_map(
        static fn ($store): string => $store->code,
        app(ListStoresHandler::class)->handle(new ListStores),
    );
}

/**
 * Stage 2b, step 3. What the stores screen is handed (frontend.md §3.5, E1).
 *
 * "Each screen shows only the stores in the person's scope." Which stores those are, and which of
 * them may be changed, are answered here rather than by the screen — so the same answer holds
 * whether the request arrives over HTTP, from the console, or from a queued job.
 */
describe('the stores a person is shown', function () {
    it('shows somebody only the stores they may see', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa']));

        expect(listedStoreCodes())->toBe(['sa']);
    });

    it('shows a Super Admin every store', function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        // Ordered as Platform orders them, which is the order a visitor chooses from.
        expect(listedStoreCodes())->toBe(['sa', 'eg', 'ae']);
    });

    it('refuses somebody who may see no store at all', function () {
        // Not an empty list: an empty list reads as "there are no stores", which is a different
        // thing and not true.
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD], ['sa']));

        expect(fn () => listedStoreCodes())->toThrow(Unauthorized::class);
    });

    it('says which of them they may change, which is a separate question', function () {
        // They read two stores and may edit one: the screen offers the form on that one only.
        $staffId = Fx::staff();
        Fx::assign($staffId, Fx::role([PlatformPermissions::STORE_VIEW, PlatformPermissions::STORE_UPDATE]), ['sa', 'eg'], [
            PlatformPermissions::STORE_UPDATE => ['eg'],
        ]);
        Fx::actAsStaff($staffId);

        $editable = [];

        foreach (app(ListStoresHandler::class)->handle(new ListStores) as $store) {
            $editable[$store->code] = $store->editable;
        }

        expect($editable)->toBe(['sa' => false, 'eg' => true]);
    });

    it('hands over the tax rate as Platform keeps it, not as a percentage', function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $stores = app(ListStoresHandler::class)->handle(new ListStores);

        // Basis points all the way to the screen: 15% is 1500, and no float is involved anywhere
        // between the table and the person reading it (platform.md §1.1).
        expect($stores[0]->taxRateBasisPoints)->toBe(1500);
    });
});
