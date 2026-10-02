<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Platform\Application\Command\ActivateStore\ActivateStore;
use Modules\Platform\Application\Command\ActivateStore\ActivateStoreHandler;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Application\Command\UpdateStore\UpdateStore;
use Modules\Platform\Application\Command\UpdateStore\UpdateStoreHandler;
use Modules\Platform\Application\Query\ListStores\ListStores;
use Modules\Platform\Application\Query\ListStores\ListStoresHandler;
use Modules\Platform\Domain\Exception\BaseStoreAlwaysActive;
use Modules\Platform\Domain\Exception\StoreNotFound;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Events\StoreActivated;
use Modules\Platform\Public\Events\StoreDeactivated;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/*
| The store on/off switch (platform.md §1.1, §1.6, §3, §5.2; owner, 2026-10-01) and the base store,
| which is always on (owner, 2026-10-02).
|
| Every helper is named after this file's subject: a function in a Pest file is global to the suite.
*/

/**
 * The switch and the mark as the table holds them.
 *
 * @return array{is_active: bool, is_base: bool}
 */
function storeSwitchRow(string $code): array
{
    $row = DB::table('platform.stores')->where('code', $code)->first(['is_active', 'is_base']) ?? throw new LogicException("No store {$code}.");

    return ['is_active' => (bool) $row->is_active, 'is_base' => (bool) $row->is_base];
}

function storeSwitchId(string $code): string
{
    return (string) DB::table('platform.stores')->where('code', $code)->value('id');
}

function storeSwitchAsSuperAdmin(): void
{
    Fx::actAsStaff(Fx::staff(superAdmin: true));
}

/**
 * @param  list<StoreDto>  $stores
 * @return list<string>
 */
function storeSwitchCodes(array $stores): array
{
    return array_map(static fn (StoreDto $store): string => $store->code, $stores);
}

describe('the seed and the migration', function () {
    it('seeds the launch stores on, with KSA alone the base store', function () {
        expect(storeSwitchRow('sa'))->toBe(['is_active' => true, 'is_base' => true])
            ->and(storeSwitchRow('eg'))->toBe(['is_active' => true, 'is_base' => false])
            ->and(storeSwitchRow('ae'))->toBe(['is_active' => true, 'is_base' => false])
            // Each one turned on as the system, and recorded like any other switch.
            ->and(Fx::audits('platform.store.activated'))->toBe(3);
    });

    it('never undoes a switch when the seed runs again', function () {
        storeSwitchAsSuperAdmin();
        app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg'));

        Fx::asSystem(fn () => seed(PlatformSeeder::class));

        expect(storeSwitchRow('eg')['is_active'])->toBeFalse()
            ->and(DB::table('platform.stores')->where('is_base', true)->pluck('code')->all())->toBe(['sa']);
    });

    it('turns on every store an installation already had, and marks KSA, when the columns are added', function () {
        $migration = require base_path('src/Modules/Platform/Infrastructure/Persistence/Migrations/2026_10_02_100000_add_platform_stores_switch.php');

        // Back to the table as it was before the switch existed, with the three stores in it.
        $migration->down();
        expect(DB::getSchemaBuilder()->hasColumn('platform.stores', 'is_active'))->toBeFalse();

        $migration->up();

        expect(storeSwitchRow('sa'))->toBe(['is_active' => true, 'is_base' => true])
            ->and(storeSwitchRow('eg'))->toBe(['is_active' => true, 'is_base' => false])
            ->and(storeSwitchRow('ae'))->toBe(['is_active' => true, 'is_base' => false]);
    });
});

describe('switching', function () {
    it('lets a Super Admin turn a store off and on again, each audited and announced', function () {
        storeSwitchAsSuperAdmin();
        Event::fake([StoreActivated::class, StoreDeactivated::class]);
        $egypt = storeSwitchId('eg');

        app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg'));
        $off = storeSwitchRow('eg');
        app(ActivateStoreHandler::class)->handle(new ActivateStore('eg'));

        $entries = DB::table('platform.audit_entries')->where('subject_id', $egypt)
            ->whereIn('action', ['platform.store.deactivated', 'platform.store.activated'])
            ->orderBy('id')->get(['action', 'store_id', 'actor_type', 'changes'])
            ->map(static fn (object $entry): array => [$entry->action, $entry->store_id, $entry->actor_type, json_decode((string) $entry->changes, true)])
            ->all();

        expect($off['is_active'])->toBeFalse()
            ->and(storeSwitchRow('eg')['is_active'])->toBeTrue()
            // The seed's own entry for this store comes first; then these two, in order.
            ->and(array_slice($entries, -2))->toBe([
                ['platform.store.deactivated', $egypt, 'STAFF', ['is_active' => [true, false]]],
                ['platform.store.activated', $egypt, 'STAFF', ['is_active' => [false, true]]],
            ]);
        Event::assertDispatched(StoreDeactivated::class, fn (StoreDeactivated $event): bool => $event->storeId === $egypt);
        Event::assertDispatched(StoreActivated::class, fn (StoreActivated $event): bool => $event->storeId === $egypt);
    });

    it('writes, audits and announces nothing when the store is already that way', function () {
        storeSwitchAsSuperAdmin();
        Event::fake([StoreActivated::class, StoreDeactivated::class]);
        $before = DB::table('platform.audit_entries')->count();

        app(ActivateStoreHandler::class)->handle(new ActivateStore('eg'));

        expect(DB::table('platform.audit_entries')->count())->toBe($before);
        Event::assertNotDispatched(StoreActivated::class);
    });

    it('refuses an admin who holds every other store permission in every store', function (string $direction) {
        Fx::actAsAdmin(['*'], [PlatformPermissions::STORE_VIEW, PlatformPermissions::STORE_UPDATE, PlatformPermissions::SETTINGS_UPDATE]);
        $before = storeSwitchRow('eg');

        expect(fn () => $direction === 'off'
            ? app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg'))
            : app(ActivateStoreHandler::class)->handle(new ActivateStore('eg')))->toThrow(Unauthorized::class)
            ->and(storeSwitchRow('eg'))->toBe($before);
    })->with(['off', 'on']);

    it('refuses to turn the base store off, and leaves it on', function () {
        storeSwitchAsSuperAdmin();
        $before = DB::table('platform.audit_entries')->count();

        expect(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('sa')))->toThrow(BaseStoreAlwaysActive::class)
            ->and(storeSwitchRow('sa'))->toBe(['is_active' => true, 'is_base' => true])
            ->and(DB::table('platform.audit_entries')->count())->toBe($before);
    });

    it('refuses the base store in code, before the database would', function () {
        storeSwitchAsSuperAdmin();
        // Only the code is left to refuse it: the CHECK is gone for this test's transaction.
        DB::statement('ALTER TABLE platform.stores DROP CONSTRAINT stores_base_always_active');

        expect(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('sa')))->toThrow(BaseStoreAlwaysActive::class)
            ->and(storeSwitchRow('sa')['is_active'])->toBeTrue();
    });

    it('keeps the database as the last line: no base store off, and one base store at most', function (string $statement) {
        expect(fn () => DB::transaction(fn () => DB::statement($statement)))->toThrow(QueryException::class);
    })->with([
        'the base store off' => ["UPDATE platform.stores SET is_active = false WHERE code = 'sa'"],
        'a second base store' => ["UPDATE platform.stores SET is_base = true WHERE code = 'eg'"],
    ]);

    it('answers a store that does not exist', function () {
        storeSwitchAsSuperAdmin();

        app(ActivateStoreHandler::class)->handle(new ActivateStore('zz'));
    })->throws(StoreNotFound::class);
});

describe('what other modules are told', function () {
    it('offers an off store nowhere, and still names it in history — from a warm cache', function () {
        $platform = app(PlatformApi::class);
        $egypt = storeSwitchId('eg');
        // Warm first, so a switch that forgot to refresh the cache would still be served.
        expect(storeSwitchCodes($platform->stores()))->toBe(['sa', 'eg', 'ae']);

        storeSwitchAsSuperAdmin();
        app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg'));

        expect(storeSwitchCodes($platform->stores()))->toBe(['sa', 'ae'])
            ->and($platform->storeByCode('eg'))->toBeNull()
            ->and($platform->storeByCode('sa')?->isBase)->toBeTrue()
            // An id from an order or an audit entry still finds the store, marked off.
            ->and($platform->store(StoreId::fromString($egypt))?->code)->toBe('eg')
            ->and($platform->store(StoreId::fromString($egypt))?->isActive)->toBeFalse()
            ->and(storeSwitchCodes($platform->allStores()))->toBe(['sa', 'eg', 'ae']);
    });
});

/**
 * @return array<string, array{bool, bool, bool}> code => [on, base, switchable]
 */
function storeSwitchListed(): array
{
    $listed = [];

    foreach (app(ListStoresHandler::class)->handle(new ListStores) as $store) {
        $listed[$store->code] = [$store->isActive, $store->isBase, $store->switchable];
    }

    return $listed;
}

describe('the stores screen', function () {
    it('lists an off store to a Super Admin, to turn it back on, and says which store may be switched', function () {
        storeSwitchAsSuperAdmin();
        app(DeactivateStoreHandler::class)->handle(new DeactivateStore('ae'));

        expect(storeSwitchListed())->toBe([
            'sa' => [true, true, false],
            'eg' => [true, false, true],
            'ae' => [false, false, true],
        ])->and(app(ListStoresHandler::class)->maySwitch())->toBeTrue();
    });

    it('never lists an off store to anyone else, even an admin of every store', function () {
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('ae')));
        Fx::actAsAdmin(['*'], [PlatformPermissions::STORE_VIEW, PlatformPermissions::STORE_UPDATE]);

        expect(storeSwitchListed())->toBe([
            'sa' => [true, true, false],
            'eg' => [true, false, false],
        ])->and(app(ListStoresHandler::class)->maySwitch())->toBeFalse();
    });

    it('answers an off store as no store to anyone who may not switch stores, and lets a Super Admin edit it', function () {
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('ae')));
        Fx::actAsAdmin(['*'], [PlatformPermissions::STORE_VIEW, PlatformPermissions::STORE_UPDATE]);

        expect(fn () => app(UpdateStoreHandler::class)->handle(new UpdateStore('ae', taxRateBasisPoints: 600)))->toThrow(StoreNotFound::class);

        storeSwitchAsSuperAdmin();
        app(UpdateStoreHandler::class)->handle(new UpdateStore('ae', taxRateBasisPoints: 600));

        expect(DB::table('platform.stores')->where('code', 'ae')->value('tax_rate_basis_points'))->toBe(600);
    });
});
