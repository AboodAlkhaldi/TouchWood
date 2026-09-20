<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Query\ListCustomers\ListCustomers;
use Modules\Access\Application\Query\ListCustomers\ListCustomersHandler;
use Modules\Access\Application\Query\ListStaff\ListStaff;
use Modules\Access\Application\Query\ListStaff\ListStaffHandler;
use Modules\Access\Application\Query\ListStaff\StaffSummary;
use Modules\Access\Application\Query\ViewCustomer\ViewCustomer;
use Modules\Access\Application\Query\ViewCustomer\ViewCustomerHandler;
use Modules\Access\Application\Query\ViewStaff\ViewStaff;
use Modules\Access\Application\Query\ViewStaff\ViewStaffHandler;
use Modules\Access\Domain\Exception\CustomerNotFound;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Enums\StaffStatus;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    FakeBreachList::install();
});

/**
 * An address of this file's own making: a Pest helper of another file is not loaded with it.
 */
function staffViewAddress(string $customerId): void
{
    DB::table('access.addresses')->insert([
        'id' => strtolower((string) Str::ulid()),
        'customer_id' => $customerId,
        'store_id' => Fx::storeId('sa'),
        'label' => 'Home',
        'recipient_name' => 'Sara Ali',
        'phone' => '+966501234567',
        'fields' => json_encode(['city' => 'Riyadh']),
        'is_default' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return list<string> the ids the list came back with
 */
function listedCustomers(?string $search = null): array
{
    $page = app(ListCustomersHandler::class)->handle(new ListCustomers($search));

    return array_map(static fn (object $customer): string => $customer->id, $page->customers);
}

/**
 * @return array<string, StaffSummary> the staff the reader sees, by id
 */
function listedStaff(): array
{
    $page = app(ListStaffHandler::class)->handle(new ListStaff);
    $byId = [];

    foreach ($page->staff as $summary) {
        $byId[$summary->id] = $summary;
    }

    return $byId;
}

describe('the customers a staff member sees (spec §3.3)', function () {
    it('shows only the customers of their own stores', function () {
        $here = Fx::customer('here@example.test', 'sa');
        $abroad = Fx::customer('abroad@example.test', 'ae');
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['sa']));

        expect(listedCustomers())->toBe([$here])
            ->and(listedCustomers())->not->toContain($abroad);
    });

    it('shows a Super Admin every store\'s customers', function () {
        $here = Fx::customer('here@example.test', 'sa');
        $abroad = Fx::customer('abroad@example.test', 'ae');
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(listedCustomers())->toHaveCount(2)
            ->and(listedCustomers())->toContain($here, $abroad);
    });

    it('finds one by part of an email, a name or a phone number', function () {
        $sara = Fx::customer('sara@example.test', 'sa');
        Fx::customer('omar@example.test', 'sa');
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['sa']));

        expect(listedCustomers('SARA@'))->toBe([$sara])
            ->and(listedCustomers('nobody'))->toBe([]);
    });

    it('refuses a staff member who may see customers nowhere', function () {
        Fx::customer();
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']));

        expect(fn () => listedCustomers())->toThrow(Unauthorized::class);
    });

    it('shows one customer with their contacts and their address book', function () {
        $customerId = Fx::customer();
        staffViewAddress($customerId);
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['sa']));

        $details = app(ViewCustomerHandler::class)->handle(new ViewCustomer($customerId));

        expect($details->customer->email)->toBe('sara@example.test')
            ->and($details->customer->homeStoreId)->toBe(Fx::storeId('sa'))
            ->and($details->locale)->toBe('en')
            ->and($details->addresses)->toHaveCount(1)
            ->and($details->addresses[0]->recipientName)->toBe('Sara Ali');
    });

    it('answers a customer of another store as no customer at all', function () {
        $abroad = Fx::customer('abroad@example.test', 'ae');
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['sa']));

        expect(fn () => app(ViewCustomerHandler::class)->handle(new ViewCustomer($abroad)))
            ->toThrow(CustomerNotFound::class);
    });
});

describe('the staff a staff member sees (amendments 9 and 43)', function () {
    it('shows an admin as a name and a role, with no contact details', function () {
        $adminId = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa'], RoleLevel::Admin);
        $colleague = Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['sa'], RoleLevel::Staff);
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']));

        $seen = listedStaff();

        expect($seen[$adminId]->isAdmin)->toBeTrue()
            ->and($seen[$adminId]->firstName)->not->toBeEmpty()
            ->and($seen[$adminId]->roleNameEn)->not->toBeEmpty()
            ->and($seen[$adminId]->email)->toBeNull()
            ->and($seen[$adminId]->phone)->toBeNull()
            ->and($seen[$adminId]->jobTitle)->toBeNull()
            // An ordinary colleague is shown in full.
            ->and($seen[$colleague]->email)->not->toBeNull()
            ->and($seen[$colleague]->isAdmin)->toBeFalse();
    });

    it('never shows a Super Admin, and never counts one', function () {
        // Put in the reader's own store behind the handlers' back — nothing in the panel gives a
        // Super Admin a role — so that being a Super Admin is the only thing that hides them.
        $superAdmin = Fx::staff(superAdmin: true);
        DB::table('access.role_assignments')->insert([
            'staff_user_id' => $superAdmin,
            'role_id' => Fx::role([AccessPermissions::CUSTOMER_VIEW]),
            'access_level' => 'SELECTED_STORES',
            'assigned_at' => now(),
        ]);
        DB::table('access.role_assignment_stores')->insert(['staff_user_id' => $superAdmin, 'store_id' => Fx::storeId('sa')]);
        Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['sa']);
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']));

        $page = app(ListStaffHandler::class)->handle(new ListStaff);

        expect(array_key_exists($superAdmin, listedStaff()))->toBeFalse()
            ->and($page->total)->toBe(count($page->staff))
            ->and(fn () => app(ViewStaffHandler::class)->handle(new ViewStaff($superAdmin)))
            ->toThrow(StaffNotFound::class);
    });

    it('shows a Super Admin everyone, in full', function () {
        $otherSuperAdmin = Fx::staff(superAdmin: true, firstName: 'Other');
        $admin = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa'], RoleLevel::Admin);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $seen = listedStaff();

        expect($seen)->toHaveKey($otherSuperAdmin)
            ->and($seen[$admin]->email)->not->toBeNull()
            ->and(app(ViewStaffHandler::class)->handle(new ViewStaff($otherSuperAdmin))->isAdmin)->toBeTrue();
    });

    it('counts only the staff the reader may see', function () {
        Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['sa', 'ae']);
        Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['ae']);
        Fx::staff(superAdmin: true);
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']));

        $page = app(ListStaffHandler::class)->handle(new ListStaff);

        // Only the reader themselves: a total counting the others would be a headcount of stores
        // they do not cover (review of step 6).
        expect($page->total)->toBe(1)
            ->and($page->staff)->toHaveCount(1);
    });

    it('sees a multi-store reader\'s whole patch, and no wider', function () {
        $both = Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['sa', 'ae']);
        $one = Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['ae']);
        $elsewhere = Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['eg']);
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa', 'ae']));

        $seen = listedStaff();

        expect($seen)->toHaveKey($both)
            ->and($seen)->toHaveKey($one)
            ->and($seen)->not->toHaveKey($elsewhere);
    });

    it('takes a search as text, not as a pattern', function () {
        Fx::customer('sara@example.test', 'sa');
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['sa']));

        // A wildcard a customer typed is a wildcard nobody meant.
        expect(listedCustomers('%'))->toBe([])
            ->and(listedCustomers('_ara@'))->toBe([])
            ->and(listedCustomers('Ali'))->toHaveCount(1);
    });

    it('hides a staff member whose stores the reader does not cover', function () {
        $wider = Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['sa', 'ae']);
        $mine = Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['sa']);
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']));

        expect(listedStaff())->toHaveKey($mine)
            ->and(listedStaff())->not->toHaveKey($wider)
            ->and(fn () => app(ViewStaffHandler::class)->handle(new ViewStaff($wider)))
            ->toThrow(StaffNotFound::class);
    });

    it('shows a disabled colleague with their status', function () {
        $disabled = Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['sa']);
        Fx::asSystem(fn () => DB::table('access.staff_users')->where('id', $disabled)->update(['status' => StaffStatus::Disabled->value]));
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']));

        expect(listedStaff()[$disabled]->status)->toBe(StaffStatus::Disabled);
    });
});
