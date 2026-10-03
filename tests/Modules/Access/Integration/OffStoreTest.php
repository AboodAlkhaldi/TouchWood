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
use Modules\Access\Application\Query\ViewCustomer\ViewCustomer;
use Modules\Access\Application\Query\ViewCustomer\ViewCustomerHandler;
use Modules\Access\Domain\Exception\AddressNotFound;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Presentation\Http\Resource\CustomerAddressGroup;
use Modules\Access\Presentation\Http\Resource\CustomerPages;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\AddressDto;
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

    it('hides them from the staff customer screen too, and gives them back when the store is on (amendment 57)', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        offStoreAddress(Fx::storeId('sa'));
        offStoreAddress(Fx::storeId('eg'), 'Cairo');
        offStoreSwitch('eg', on: false);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $details = app(ViewCustomerHandler::class)->handle(new ViewCustomer($customerId));
        $page = app(CustomerPages::class)->view($customerId);

        expect(array_map(static fn (AddressDto $address): string => $address->storeId, $details->addresses))->toBe([Fx::storeId('sa')])
            ->and(array_map(static fn (CustomerAddressGroup $group): string => $group->storeId, $page->addresses))->toBe([Fx::storeId('sa')])
            ->and(DB::table('access.addresses')->where('customer_id', $customerId)->count())->toBe(2);

        offStoreSwitch('eg', on: true);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(app(ViewCustomerHandler::class)->handle(new ViewCustomer($customerId))->addresses)->toHaveCount(2);
    });

    it('names no home store on the staff screens when it is off, never showing its id instead', function () {
        $egypt = Fx::storeId('eg');
        $customerId = Fx::customer('cairo@example.test', 'eg');
        offStoreSwitch('eg', on: false);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $listed = app(CustomerPages::class)->list(null, null, null, 1)->customers;

        expect(app(CustomerPages::class)->view($customerId)->customer->homeStore)->toBe('')
            ->and($listed)->toHaveCount(1)
            ->and($listed[0]->homeStore)->toBe('')
            ->and($listed[0]->homeStore)->not->toBe($egypt);
    });
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
