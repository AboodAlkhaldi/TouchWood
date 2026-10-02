<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Command\ChooseCurrentStore\ChooseCurrentStore;
use Modules\Access\Application\Command\ChooseCurrentStore\ChooseCurrentStoreHandler;
use Modules\Access\Application\Command\DeleteAddress\DeleteAddress;
use Modules\Access\Application\Command\DeleteAddress\DeleteAddressHandler;
use Modules\Access\Application\Command\SaveAddress\SaveAddress;
use Modules\Access\Application\Command\SaveAddress\SaveAddressHandler;
use Modules\Access\Application\Command\SetDefaultAddress\SetDefaultAddress;
use Modules\Access\Application\Command\SetDefaultAddress\SetDefaultAddressHandler;
use Modules\Access\Application\Query\CurrentStore\CurrentStoreForStaff;
use Modules\Access\Application\Query\MyAccount\MyAddressesForCustomer;
use Modules\Access\Application\Query\MyAccount\MyAddressesInStoreDto;
use Modules\Access\Domain\Exception\AddressNotFound;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Platform\Application\Command\ActivateStore\ActivateStore;
use Modules\Platform\Application\Command\ActivateStore\ActivateStoreHandler;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    FakeBreachList::install();
    seed(PlatformSeeder::class);
});

/*
| An off store, seen from Access (platform.md §1.6; access.md §1.1, §1.9, amendment 53; owner,
| 2026-10-01): its addresses are hidden, not deleted, and come back as they were; its country is not
| offered; it is not a store to work in.
|
| Every helper is named after this file's subject: a function in a Pest file is global to the suite.
*/

function offStoreSwitch(string $code, bool $on): void
{
    Fx::asSystem(fn () => $on
        ? app(ActivateStoreHandler::class)->handle(new ActivateStore($code))
        : app(DeactivateStoreHandler::class)->handle(new DeactivateStore($code)));
}

function offStoreAddress(string $storeId, string $label = 'Home'): string
{
    return app(SaveAddressHandler::class)->handle(new SaveAddress(
        storeId: $storeId,
        label: $label,
        recipientName: 'Sara Ali',
        phone: '+966501234567',
        fields: ['administrative_area' => 'Region', 'city' => 'City', 'district' => 'District', 'street' => 'Street', 'building' => '7'],
    ));
}

/**
 * @return list<string> the store codes the customer's address book shows
 */
function offStoreBook(): array
{
    return array_map(static fn (MyAddressesInStoreDto $store): string => $store->storeCode, app(MyAddressesForCustomer::class)->forCurrentCustomer());
}

describe('a customer\'s addresses in an off store', function () {
    it('hides them from every read while the store is off, and gives them back as they were', function () {
        $egypt = Fx::storeId('eg');
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        $home = offStoreAddress(Fx::storeId('sa'));
        $cairo = offStoreAddress($egypt, 'Cairo');
        $access = app(AccessApi::class);
        expect($access->addresses($customerId, $egypt))->toHaveCount(1);

        offStoreSwitch('eg', on: false);
        Fx::actAsCustomer($customerId);

        expect($access->address($cairo))->toBeNull()
            ->and($access->addresses($customerId, $egypt))->toBe([])
            ->and($access->address($home)?->id)->toBe($home)
            ->and(offStoreBook())->toBe(['sa', 'ae'])
            // Hidden, not deleted.
            ->and(DB::table('access.addresses')->where('id', $cairo)->exists())->toBeTrue();

        offStoreSwitch('eg', on: true);
        Fx::actAsCustomer($customerId);

        expect($access->address($cairo)?->isDefault)->toBeTrue()
            ->and($access->addresses($customerId, $egypt))->toHaveCount(1)
            ->and(offStoreBook())->toBe(['sa', 'eg', 'ae']);
    });

    it('does not offer the store\'s country for a new address, refusing it as an unknown store', function () {
        $egypt = Fx::storeId('eg');
        $customerId = Fx::customer();
        offStoreSwitch('eg', on: false);
        Fx::actAsCustomer($customerId);

        expect(fn () => offStoreAddress($egypt))->toThrow(InvalidAccessAttribute::class)
            ->and(DB::table('access.addresses')->count())->toBe(0);
    });

    it('answers a hidden address as no address when it is deleted or made the default', function (string $action) {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        $cairo = offStoreAddress(Fx::storeId('eg'));
        offStoreSwitch('eg', on: false);
        Fx::actAsCustomer($customerId);

        expect(fn () => $action === 'delete'
            ? app(DeleteAddressHandler::class)->handle(new DeleteAddress($cairo))
            : app(SetDefaultAddressHandler::class)->handle(new SetDefaultAddress($cairo)))->toThrow(AddressNotFound::class)
            ->and(DB::table('access.addresses')->where('id', $cairo)->exists())->toBeTrue();
    })->with(['delete', 'make default']);
});

describe('working in an off store', function () {
    it('never offers it in the panel, not even to a Super Admin, and refuses it as their store', function () {
        $egypt = Fx::storeId('eg');
        offStoreSwitch('eg', on: false);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $current = app(CurrentStoreForStaff::class)->forCurrentStaff();

        expect($current?->available)->toBe([Fx::storeId('sa'), Fx::storeId('ae')])
            ->and(fn () => app(ChooseCurrentStoreHandler::class)->handle(new ChooseCurrentStore($egypt)))
            ->toThrow(InvalidAccessAttribute::class);
    });

    it('lets a staff member whose only store is off sign in to no store at all', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW], ['eg']);
        offStoreSwitch('eg', on: false);
        Fx::actAsStaff($staffId);

        $current = app(CurrentStoreForStaff::class)->forCurrentStaff();

        expect($current?->storeId)->toBeNull()
            ->and($current?->available)->toBe([]);
    });

    it('falls back from a remembered store that was turned off', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa', 'eg']);
        Fx::actAsStaff($staffId);
        app(ChooseCurrentStoreHandler::class)->handle(new ChooseCurrentStore(Fx::storeId('eg')));
        $saudi = Fx::storeId('sa');

        offStoreSwitch('eg', on: false);
        Fx::actAsStaff($staffId);
        $current = app(CurrentStoreForStaff::class)->forCurrentStaff();

        expect($current?->storeId)->toBe($saudi)
            ->and($current?->fellBack)->toBeTrue();
    });
});
