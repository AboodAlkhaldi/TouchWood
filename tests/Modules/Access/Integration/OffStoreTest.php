<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Command\ChangeStaffRole\ChangeStaffRole;
use Modules\Access\Application\Command\ChangeStaffRole\ChangeStaffRoleHandler;
use Modules\Access\Application\Command\DeleteAddress\DeleteAddress;
use Modules\Access\Application\Command\DeleteAddress\DeleteAddressHandler;
use Modules\Access\Application\Command\SaveAddress\SaveAddress;
use Modules\Access\Application\Command\SaveAddress\SaveAddressHandler;
use Modules\Access\Application\Command\SetDefaultAddress\SetDefaultAddress;
use Modules\Access\Application\Command\SetDefaultAddress\SetDefaultAddressHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Query\ListRoles\ListRolesHandler;
use Modules\Access\Application\Query\ListStaff\ListStaffHandler;
use Modules\Access\Application\Query\MyAccount\MyAddressesForCustomer;
use Modules\Access\Application\Query\MyAccount\MyAddressesInStoreDto;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissionsHandler;
use Modules\Access\Application\Query\StoresForStaff\StoresForStaff;
use Modules\Access\Application\Query\ViewCustomer\ViewCustomer;
use Modules\Access\Application\Query\ViewCustomer\ViewCustomerHandler;
use Modules\Access\Application\Query\ViewRole\ViewRoleHandler;
use Modules\Access\Domain\Exception\AddressNotFound;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Presentation\Http\Resource\CustomerAddressGroup;
use Modules\Access\Presentation\Http\Resource\CustomerPages;
use Modules\Access\Presentation\Http\Resource\RolePages;
use Modules\Access\Presentation\Http\Resource\StaffGroup;
use Modules\Access\Presentation\Http\Resource\StaffPages;
use Modules\Access\Presentation\Http\Resource\StoreOption;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\AddressDto;
use Modules\Access\Public\Enums\AccessLevel;
use Modules\Platform\Application\Command\ActivateStore\ActivateStore;
use Modules\Platform\Application\Command\ActivateStore\ActivateStoreHandler;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Application\Command\UpdateStore\UpdateStore;
use Modules\Platform\Application\Command\UpdateStore\UpdateStoreHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
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

/**
 * @param  list<CustomerAddressGroup>  $groups
 */
function offStoreGroup(array $groups, string $storeId): CustomerAddressGroup
{
    foreach ($groups as $group) {
        if ($group->storeId === $storeId) {
            return $group;
        }
    }

    throw new RuntimeException("No address group for store {$storeId}.");
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
        // The next request: one in flight keeps the stores it read (platform.md §9.11).
        Fx::actAsCustomer($customerId);
        $access = app(AccessApi::class);

        expect($access->address($cairo))->toBeNull()
            ->and($access->addresses($customerId, $egypt))->toBe([])
            ->and($access->address($home)?->id)->toBe($home)
            ->and(offStoreBook())->toBe(['sa', 'ae'])
            // Hidden, not deleted.
            ->and(DB::table('access.addresses')->where('id', $cairo)->exists())->toBeTrue();

        offStoreSwitch('eg', on: true);
        Fx::actAsCustomer($customerId);
        $access = app(AccessApi::class);

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

    it('shows them on the staff customer screen, named and marked Off, while the customer still cannot use them (amendment 58(d))', function () {
        $saudi = Fx::storeId('sa');
        // Read while it is on: an off store has no id in the fixtures.
        $egypt = Fx::storeId('eg');
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        offStoreAddress($saudi);
        offStoreAddress($egypt, 'Cairo');
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $egyptName = offStoreGroup(app(CustomerPages::class)->view($customerId)->addresses, $egypt)->storeName;
        offStoreSwitch('eg', on: false);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $details = app(ViewCustomerHandler::class)->handle(new ViewCustomer($customerId));
        $page = app(CustomerPages::class)->view($customerId);

        expect(array_map(static fn (AddressDto $address): string => $address->storeId, $details->addresses))->toEqualCanonicalizing([$saudi, $egypt])
            ->and(offStoreGroup($page->addresses, $saudi)->isActive)->toBeTrue()
            ->and(offStoreGroup($page->addresses, $egypt)->isActive)->toBeFalse()
            ->and($egyptName)->not->toBe('')
            ->and(offStoreGroup($page->addresses, $egypt)->storeName)->toBe($egyptName)
            ->and(offStoreGroup($page->addresses, $egypt)->addresses)->toHaveCount(1);

        // The customer's own address book still leaves it out while the store is off (55(a) stands).
        Fx::actAsCustomer($customerId);

        expect(offStoreBook())->toBe(['sa', 'ae']);
    });

    it('names an off home store on the staff screens with an Off flag, never its id (amendment 58(c)(d))', function () {
        $egypt = Fx::storeId('eg');
        $customerId = Fx::customer('cairo@example.test', 'eg');
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $name = app(CustomerPages::class)->view($customerId)->customer->homeStore;
        offStoreSwitch('eg', on: false);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $listed = app(CustomerPages::class)->list(null, null, null, 1)->customers;
        $viewed = app(CustomerPages::class)->view($customerId)->customer;

        expect($name)->not->toBe('')
            ->and($viewed->homeStore)->toBe($name)
            ->and($viewed->homeStoreIsActive)->toBeFalse()
            ->and($listed)->toHaveCount(1)
            ->and($listed[0]->homeStore)->toBe($name)
            ->and($listed[0]->homeStoreIsActive)->toBeFalse()
            ->and($listed[0]->homeStore)->not->toBe($egypt);
    });
});

/*
| An off store in the panel's store lists (platform.md §1.6, §9.10 #4; access.md amendments 58(a),
| 64; the owner, 2026-10-06): a Super Admin is offered it everywhere, marked Off, to prepare it
| before it opens; anyone else, the staff who cover it included, is never offered it, and asking
| for it by its code is refused.
*/
describe('an off store in the panel\'s store lists', function () {
    it('offers it to a Super Admin, marked off, in Home\'s switcher, View Store and every store filter', function () {
        offStoreSwitch('eg', on: false);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $mine = array_map(static fn (StoreDto $store): array => [$store->code, $store->isActive], app(StoresForStaff::class)->forCurrentStaff());
        $filter = array_map(static fn (StoreDto $store): array => [$store->code, $store->isActive], app(StoreChoices::class)->forJobs(PlatformPermissions::STORE_VIEW));
        $asked = app(StoreChoices::class)->chosen('eg', PlatformPermissions::STORE_VIEW);

        expect($mine)->toBe([['sa', true], ['eg', false], ['ae', true]])
            ->and($filter)->toBe([['sa', true], ['eg', false], ['ae', true]])
            ->and($asked?->code)->toBe('eg')
            ->and($asked?->isActive)->toBeFalse();
    });

    it('never offers it to a staff member who covers it, and refuses it when asked for', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa', 'eg']);
        offStoreSwitch('eg', on: false);
        Fx::actAsStaff($staffId);

        $codes = static fn (array $stores): array => array_map(static fn (StoreDto $store): string => $store->code, $stores);

        expect($codes(app(StoresForStaff::class)->forCurrentStaff()))->toBe(['sa'])
            ->and($codes(app(StoreChoices::class)->forJobs(PlatformPermissions::STORE_VIEW)))->toBe(['sa'])
            ->and(app(StoresForStaff::class)->byCode('eg'))->toBeNull()
            ->and(fn () => app(StoreChoices::class)->chosen('eg', PlatformPermissions::STORE_VIEW))->toThrow(Unauthorized::class);
    });

    it('never shows it to a staff member who does not cover it', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa', 'ae']));
        offStoreSwitch('eg', on: false);

        expect(array_map(static fn (StoreDto $store): string => $store->code, app(StoresForStaff::class)->forCurrentStaff()))->toBe(['sa', 'ae']);
    });

    it('leaves a staff member whose only store is off with no store at all', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW], ['eg']);
        offStoreSwitch('eg', on: false);
        Fx::actAsStaff($staffId);

        expect(app(StoresForStaff::class)->forCurrentStaff())->toBe([])
            ->and(app(StoreChoices::class)->forJobs(PlatformPermissions::STORE_VIEW))->toBe([])
            ->and(app(StoreChoices::class)->chosen(null, PlatformPermissions::STORE_VIEW))->toBeNull();
    });

    it('never opens a screen on an off store by default, a Super Admin\'s included, even when it comes first', function () {
        // Egypt moved before Saudi Arabia, then switched off: a screen asked for no store opens on
        // the first store that is on. An off store is one somebody chooses to prepare (the review of
        // the foundation, 2026-10-03; kept by platform.md §9.10 #3).
        Fx::asSystem(fn () => app(UpdateStoreHandler::class)->handle(new UpdateStore('eg', position: 0)));
        offStoreSwitch('eg', on: false);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(app(StoreChoices::class)->forJobs(PlatformPermissions::STORE_VIEW)[0]->code ?? null)->toBe('eg')
            ->and(app(StoreChoices::class)->chosen(null, PlatformPermissions::STORE_VIEW)?->code)->toBe('sa')
            ->and(app(StoreChoices::class)->chosen('', PlatformPermissions::STORE_VIEW)?->code)->toBe('sa');
    });
});

/*
| A staff member's stores while one of them is off (access.md amendment 58(b); owner, 2026-10-03):
| saving keeps it, and an admin who covers it may still give it or take it away.
*/
describe('a staff member\'s stores while one is off', function () {
    /** An admin who may assign roles in Saudi Arabia and Egypt, acting now. */
    function offStoreEditor(): void
    {
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_VIEW, AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_VIEW], ['sa', 'eg'], RoleLevel::Admin));
    }

    /**
     * The stores a staff member's assignment covers now, the off ones included, in the stores'
     * order - read from the assignment itself, since no list the panel offers them holds an off
     * store any more (amendment 64).
     *
     * @return list<string>
     */
    function offStoreCovered(string $staffId): array
    {
        $choice = app(RoleAssignmentRepository::class)->byStaff($staffId)?->staffStores();

        return array_values(array_map(
            static fn (StoreDto $store): string => $store->id,
            array_filter(app(PlatformApi::class)->allStores(), static fn (StoreDto $store): bool => $choice?->covers(StoreId::fromString($store->id)) ?? false),
        ));
    }

    it('keeps an off store on a staff member when their stores are saved', function () {
        [$saudi, $egypt] = [Fx::storeId('sa'), Fx::storeId('eg')];
        $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa', 'eg']);
        $roleId = Fx::roleOf($staffId);
        offStoreEditor();
        offStoreSwitch('eg', on: false);

        app(ChangeStaffRoleHandler::class)->handle(new ChangeStaffRole($staffId, AccessLevel::SelectedStores, [$saudi, $egypt], savedRoleId: $roleId));

        expect(offStoreCovered($staffId))->toBe([$saudi, $egypt]);
    });

    it('lets an admin who covers an off store give it to somebody while it is off', function () {
        [$saudi, $egypt] = [Fx::storeId('sa'), Fx::storeId('eg')];
        $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa']);
        $roleId = Fx::roleOf($staffId);
        offStoreEditor();
        offStoreSwitch('eg', on: false);

        app(ChangeStaffRoleHandler::class)->handle(new ChangeStaffRole($staffId, AccessLevel::SelectedStores, [$saudi, $egypt], savedRoleId: $roleId));

        expect(offStoreCovered($staffId))->toBe([$saudi, $egypt]);
    });

    it('offers the off store in the editor flagged off, and names it marked on the staff screens', function () {
        // Taken while Egypt is on: an off store has no id in the fixtures.
        [$saudi, $egypt] = [Fx::storeId('sa'), Fx::storeId('eg')];
        $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa', 'eg']);
        $soloId = Fx::staffWith([PlatformPermissions::STORE_VIEW], ['eg']);
        offStoreEditor();
        offStoreSwitch('eg', on: false);

        $editor = app(StaffPages::class)->roleEditor(app(ListRolesHandler::class), app(RoleEditorPermissionsHandler::class), $staffId);
        $offered = array_column(array_map(static fn (StoreOption $store): array => [$store->id, $store->isActive], $editor->stores), 1, 0);

        expect($offered)->toBe([$saudi => true, $egypt => false]);

        // On the staff member's page, the list and the role page alike, the off store is named and
        // marked (amendment 58(b)), read by a Super Admin who sees every person and every holder.
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $member = app(StaffPages::class)->member($staffId);
        $groups = app(StaffPages::class)->list(app(ListStaffHandler::class), null, null)->groups;
        $egyptGroup = array_values(array_filter($groups, static fn (StaffGroup $group): bool => $group->key === $egypt))[0] ?? null;
        $holders = app(RolePages::class)->one(app(ViewRoleHandler::class), app(ListRolesHandler::class), Fx::roleOf($soloId))->holders;

        // In the reader's own language, whichever it is: the mark is the word Off, in English or Arabic.
        $marked = '/ · (Off|متوقف)$/u';

        expect($member->storeNames)->toHaveCount(2)
            ->and($member->storeNames[0])->not->toMatch($marked)
            ->and($member->storeNames[1])->toMatch($marked)
            ->and((string) $egyptGroup?->label)->toMatch($marked)
            ->and($holders[0]->storeNames ?? [])->toHaveCount(1)
            ->and(($holders[0]->storeNames ?? [''])[0])->toMatch($marked);
    });
});
