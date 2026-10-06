<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Application\Query\StoresForStaff\StoresForStaff;
use Modules\Platform\Application\Command\ActivateStore\ActivateStore;
use Modules\Platform\Application\Command\ActivateStore\ActivateStoreHandler;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Actor;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/*
| A staff member's stores (access.md amendment 64; the owner, 2026-10-06): the panel has no store
| "worked in" any more, so nothing is remembered on the account. What Home's switcher and View
| Store's menu offer is read each time from the person's reach.
|
| Every helper is named after this file's subject: a function in a Pest file is global to the suite.
*/

/**
 * @return list<string> the codes of the stores the person acting now is offered
 */
function storesForStaffCodes(): array
{
    return array_map(static fn (StoreDto $store): string => $store->code, app(StoresForStaff::class)->forCurrentStaff());
}

function storesForStaffSwitch(string $code, bool $on): void
{
    Fx::asSystem(fn () => $on
        ? app(ActivateStoreHandler::class)->handle(new ActivateStore($code))
        : app(DeactivateStoreHandler::class)->handle(new DeactivateStore($code)));
}

describe('the stores a staff member is offered', function () {
    it('gives a Super Admin every store in the stores\' order, holding no role at all', function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(storesForStaffCodes())->toBe(['sa', 'eg', 'ae']);
    });

    it('gives a Super Admin the stores that are off too, to prepare them before they open', function () {
        storesForStaffSwitch('eg', on: false);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $stores = app(StoresForStaff::class)->forCurrentStaff();

        expect(array_map(static fn (StoreDto $store): array => [$store->code, $store->isActive], $stores))
            ->toBe([['sa', true], ['eg', false], ['ae', true]]);
    });

    it('gives anyone else the stores of their assignment, in the stores\' order', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_VIEW], ['ae', 'sa']));

        expect(storesForStaffCodes())->toBe(['sa', 'ae']);
    });

    it('leaves out a store of theirs that is off, and never shows one that is not theirs', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa', 'eg']);
        storesForStaffSwitch('eg', on: false);
        Fx::actAsStaff($staffId);

        expect(storesForStaffCodes())->toBe(['sa']);

        storesForStaffSwitch('eg', on: true);
        Fx::actAsStaff($staffId);

        expect(storesForStaffCodes())->toBe(['sa', 'eg']);
    });

    it('gives someone whose only store is off no store at all', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW], ['eg']);
        storesForStaffSwitch('eg', on: false);
        Fx::actAsStaff($staffId);

        expect(storesForStaffCodes())->toBe([]);
    });

    it('follows the assignment\'s own store row, which every action\'s own stores lie inside (amendment 59)', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE, PlatformPermissions::STORE_VIEW], ['sa', 'ae'], exceptions: [PlatformPermissions::STORE_UPDATE => ['sa']]);
        // An action's own store outside the row, put there by hand: it adds no store.
        DB::table('access.role_assignment_exception_stores')->insert(['staff_user_id' => $staffId, 'permission' => PlatformPermissions::STORE_UPDATE, 'store_id' => Fx::storeId('eg')]);
        Fx::actAsStaff($staffId);

        expect(storesForStaffCodes())->toBe(['sa', 'ae']);
    });

    it('gives a guest and a customer no store', function () {
        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));

        expect(storesForStaffCodes())->toBe([]);

        Fx::actAsCustomer(Fx::customer());

        expect(storesForStaffCodes())->toBe([]);
    });
});

describe('one of them, by its code', function () {
    it('finds one of theirs, whatever case or spaces it came with', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa', 'ae']));

        expect(app(StoresForStaff::class)->byCode('ae')?->id)->toBe(Fx::storeId('ae'))
            ->and(app(StoresForStaff::class)->byCode(' AE ')?->id)->toBe(Fx::storeId('ae'));
    });

    it('answers a store that is not theirs, one that does not exist and a malformed code alike: none', function (string $code) {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa']));

        expect(app(StoresForStaff::class)->byCode($code))->toBeNull();
    })->with([
        'another store' => ['eg'],
        'no such store' => ['zz'],
        'not a code' => ['../sa'],
        'empty' => [''],
    ]);

    it('finds a Super Admin\'s off store, and nobody else\'s', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa', 'eg']);
        storesForStaffSwitch('eg', on: false);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $found = app(StoresForStaff::class)->byCode('eg');

        Fx::actAsStaff($staffId);

        expect($found?->code)->toBe('eg')
            ->and($found?->isActive)->toBeFalse()
            ->and(app(StoresForStaff::class)->byCode('eg'))->toBeNull();
    });
});

it('keeps no store on the staff member\'s account any more', function () {
    // The column that remembered the store worked in is gone with it (access.md amendment 64). Read
    // from PostgreSQL itself, with a column that is there, so the question cannot pass by being
    // asked of the wrong table.
    $columns = DB::table('information_schema.columns')
        ->where('table_schema', 'access')
        ->where('table_name', 'staff_users')
        ->pluck('column_name')
        ->all();

    expect($columns)->toContain('session_version')
        ->and($columns)->not->toContain('current_store_id');
});
