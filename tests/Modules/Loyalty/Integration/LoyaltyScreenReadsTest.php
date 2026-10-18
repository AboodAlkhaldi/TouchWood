<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Loyalty\Application\Command\AddPoints\AddPoints;
use Modules\Loyalty\Application\Command\AddPoints\AddPointsHandler;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Points\PointsTabOffered;
use Modules\Loyalty\Application\Query\CustomerPointsInStores\CustomerPointsInStores;
use Modules\Loyalty\Application\Query\CustomerPointsInStores\CustomerPointsInStoresHandler;
use Modules\Loyalty\Application\Query\CustomerPointsInStores\StorePoints;
use Modules\Loyalty\Application\Query\ListPointHolders\ListPointHolders;
use Modules\Loyalty\Application\Query\ListPointHolders\ListPointHoldersHandler;
use Modules\Loyalty\Application\Query\ListPointHolders\PointHolder;
use Modules\Loyalty\Application\Query\LoyaltyActions\LoyaltyActionsForReader;
use Modules\Loyalty\Application\Query\ViewCustomerPoints\ViewCustomerPoints;
use Modules\Loyalty\Application\Query\ViewCustomerPoints\ViewCustomerPointsHandler;
use Modules\Loyalty\Application\Settings\ProgrammeSettings;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\PointsAccountNotFound;
use Shared\Application\StoreContext;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;

use function Pest\Laravel\seed;

/*
| The reads Loyalty's screens add (loyalty.md §4.1): the store's points holders (L1) with its search,
| a customer's points in every store the reader covers (L5), what a reader may do (L2), the Points
| tab's condition (L7), and ViewCustomerPoints tightened for a customer with no points (§9.5 P7).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Lx::standInForSales();
    Lx::programme();
    Lx::programme('eg');
    CarbonImmutable::setTestNow('2026-01-01 12:00:00 UTC');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** A customer of a store, with their own name, and points given by hand there. */
function screenReadsCustomer(string $email, string $first, string $storeCode = 'sa', int $points = 0, string $pointsStore = 'sa'): string
{
    $customer = Fx::customer($email, $storeCode);
    DB::table('access.customers')->where('id', $customer)->update(['first_name' => $first, 'last_name' => 'Test']);

    if ($points > 0) {
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        app(AddPointsHandler::class)->handle(new AddPoints($customer, Fx::storeId($pointsStore), $points, 'Goodwill'));
    }

    return $customer;
}

/**
 * @param  list<PointHolder>  $rows
 * @return list<string|null> the page's customers' names, in order
 */
function screenReadsNames(array $rows): array
{
    return array_map(static fn (PointHolder $row): ?string => $row->customer->name, $rows);
}

it('lists a store\'s holders by live points, most first, a page at a time, never skipping or repeating one', function () {
    $low = screenReadsCustomer('low@example.test', 'Low', points: 100);
    $high = screenReadsCustomer('high@example.test', 'High', points: 900);
    $mid = screenReadsCustomer('mid@example.test', 'Mid', points: 500);
    // Points elsewhere are not this store's.
    screenReadsCustomer('elsewhere@example.test', 'Elsewhere', 'eg', 700, 'eg');
    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW], ['sa']));

    $first = app(ListPointHoldersHandler::class)->handle(new ListPointHolders(Fx::storeId('sa'), perPage: 2));
    $second = app(ListPointHoldersHandler::class)->handle(new ListPointHolders(Fx::storeId('sa'), afterPoints: $first->nextPoints, afterAccountId: $first->nextAccountId, perPage: 2));

    expect(screenReadsNames($first->rows))->toBe(['High Test', 'Mid Test'])
        ->and(array_map(static fn (PointHolder $row): int => $row->livePoints, $first->rows))->toBe([900, 500])
        ->and($first->rows[0]->worth->minorUnits)->toBe(900)
        ->and(screenReadsNames($second->rows))->toBe(['Low Test'])
        ->and($second->nextAccountId)->toBeNull()
        ->and([$high, $mid, $low])->each->toBeString();
});

it('counts only live points: a lot past its date is not counted though the stored balance still holds it, and such an account comes last', function () {
    Lx::programme(settings: [ProgrammeSettings::EXPIRY_MONTHS => 1]);
    $gone = screenReadsCustomer('gone@example.test', 'Gone', points: 900);
    CarbonImmutable::setTestNow('2026-01-20 12:00:00 UTC');
    screenReadsCustomer('kept@example.test', 'Kept', points: 100);
    // Past the first lot's date; nobody has swept it, so its account's stored balance still says 900.
    CarbonImmutable::setTestNow('2026-02-01 12:00:01 UTC');
    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW], ['sa']));

    $page = app(ListPointHoldersHandler::class)->handle(new ListPointHolders(Fx::storeId('sa')));

    expect(DB::table('loyalty.accounts')->where('customer_id', $gone)->value('balance'))->toBe(900)
        ->and(screenReadsNames($page->rows))->toBe(['Kept Test', 'Gone Test'])
        ->and(array_map(static fn (PointHolder $row): int => $row->livePoints, $page->rows))->toBe([100, 0])
        ->and($page->rows[1]->expiringNext)->toBeNull();
});

it('sums "expiring next" over every lot ending on the soonest day in the store\'s zone', function () {
    $customer = screenReadsCustomer('lots@example.test', 'Lots');
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    // Two lots a year from 21:00 and 23:00 UTC on 1 January: both 2 January in Riyadh (UTC+3).
    CarbonImmutable::setTestNow('2026-01-01 21:00:00 UTC');
    app(AddPointsHandler::class)->handle(new AddPoints($customer, Fx::storeId('sa'), 100, 'One'));
    CarbonImmutable::setTestNow('2026-01-01 23:00:00 UTC');
    app(AddPointsHandler::class)->handle(new AddPoints($customer, Fx::storeId('sa'), 50, 'Two'));
    CarbonImmutable::setTestNow('2026-01-03 12:00:00 UTC');
    app(AddPointsHandler::class)->handle(new AddPoints($customer, Fx::storeId('sa'), 7, 'Later'));

    $row = app(ListPointHoldersHandler::class)->handle(new ListPointHolders(Fx::storeId('sa')))->rows[0];

    expect([$row->livePoints, $row->expiringNext?->points, $row->expiringNext?->day])->toBe([157, 150, '2027-01-02']);
});

it('shows a holder\'s email only to a reader who may view that customer, and a deleted customer as deleted', function () {
    $here = screenReadsCustomer('here@example.test', 'Here', 'sa', 300);
    $there = screenReadsCustomer('there@example.test', 'There', 'eg', 200);
    $deleted = screenReadsCustomer('deleted@example.test', 'Deleted', 'sa', 100);
    DB::table('access.customers')->where('id', $deleted)->update(['anonymized_at' => '2026-01-01 00:00:00+00']);
    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW, AccessPermissions::CUSTOMER_VIEW], ['sa']));

    $rows = app(ListPointHoldersHandler::class)->handle(new ListPointHolders(Fx::storeId('sa')))->rows;
    $by = [];

    foreach ($rows as $row) {
        $by[$row->customer->customerId] = [$row->customer->name, $row->customer->email, $row->customer->deleted];
    }

    // The customer registered in Egypt with points in Saudi Arabia: the name only (§9.5 P1).
    expect($by[$here])->toBe(['Here Test', 'here@example.test', false])
        ->and($by[$there])->toBe(['There Test', null, false])
        ->and($by[$deleted])->toBe([null, null, true]);
});

it('searches names in every store, emails and phones only among the customers the reader may view', function () {
    $there = screenReadsCustomer('zed@example.test', 'Zainab', 'eg', 200);
    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW, AccessPermissions::CUSTOMER_VIEW], ['sa']));
    $search = static fn (string $text): array => array_map(
        static fn (PointHolder $row): string => $row->customer->customerId,
        app(ListPointHoldersHandler::class)->handle(new ListPointHolders(Fx::storeId('sa'), $text))->rows,
    );

    expect($search('zain'))->toBe([$there])
        // An email of a customer this reader may not view names nobody (§9.5 P6).
        ->and($search('zed@example'))->toBe([]);

    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW, AccessPermissions::CUSTOMER_VIEW], ['sa', 'eg']));

    expect($search('zed@example'))->toBe([$there]);
});

it('refuses a search shorter than 3 characters, and says too many when Access reaches its most', function () {
    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW], ['sa']));

    expect(fn () => app(ListPointHoldersHandler::class)->handle(new ListPointHolders(Fx::storeId('sa'), ' ab ')))->toThrow(InvalidPointsAttribute::class);

    // Only the search is asked before the answer: Access reaching its most is all this needs.
    $access = Mockery::mock(AccessApi::class);
    $access->shouldReceive('customerIdsMatching')->andReturn(array_fill(0, ListPointHoldersHandler::SEARCH_MOST, (string) Str::ulid()));
    app()->instance(AccessApi::class, $access);

    $page = app(ListPointHoldersHandler::class)->handle(new ListPointHolders(Fx::storeId('sa'), 'sara'));

    expect([$page->tooMany, $page->rows, $page->nextAccountId])->toBe([true, [], null]);
});

it('reads the holders of a store the reader covers only: another is not found, no job is refused', function () {
    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW], ['sa']));

    expect(fn () => app(ListPointHoldersHandler::class)->handle(new ListPointHolders(Fx::storeId('eg'))))->toThrow(PointsAccountNotFound::class);

    Fx::actAsStaff(Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['sa']));

    expect(fn () => app(ListPointHoldersHandler::class)->handle(new ListPointHolders(Fx::storeId('sa'))))->toThrow(Unauthorized::class);
});

it('answers "not found" for a customer with no points in the store, unless the reader may view the customer (§9.5 P7)', function () {
    $customer = screenReadsCustomer('none@example.test', 'None');
    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW], ['sa']));

    expect(fn () => app(ViewCustomerPointsHandler::class)->handle(new ViewCustomerPoints($customer, Fx::storeId('sa'))))->toThrow(PointsAccountNotFound::class);

    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW, AccessPermissions::CUSTOMER_VIEW], ['sa']));
    $view = app(ViewCustomerPointsHandler::class)->handle(new ViewCustomerPoints($customer, Fx::storeId('sa')));

    expect([$view->balance, $view->lots, $view->history])->toBe([0, [], []]);
});

it('still shows points a customer has in the store to a reader who may not view the customer', function () {
    $customer = screenReadsCustomer('elsewhere@example.test', 'Elsewhere', 'eg', 40);
    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW], ['sa']));

    expect(app(ViewCustomerPointsHandler::class)->handle(new ViewCustomerPoints($customer, Fx::storeId('sa')))->balance)->toBe(40);
});

it('reads one customer\'s points in every store the reader covers, with where a first point may be given', function () {
    $customer = screenReadsCustomer('both@example.test', 'Both', 'sa', 300);
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(AddPointsHandler::class)->handle(new AddPoints($customer, Fx::storeId('eg'), 20, 'Egypt'));
    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW, AccessPermissions::CUSTOMER_VIEW], ['sa', 'eg', 'ae']));

    $points = app(CustomerPointsInStoresHandler::class)->handle(new CustomerPointsInStores($customer));

    expect(array_map(static fn (StorePoints $store): array => [$store->storeCode, $store->livePoints, $store->mayOpen], $points->stores))->toBe([['sa', 300, true], ['eg', 20, true]])
        // No job to change points anywhere: no first point to give.
        ->and($points->giveStoreCode)->toBeNull();

    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW, LoyaltyPermissions::ADJUST, AccessPermissions::CUSTOMER_VIEW], ['sa', 'ae'], RoleLevel::Admin));
    $points = app(CustomerPointsInStoresHandler::class)->handle(new CustomerPointsInStores($customer));

    expect(array_map(static fn (StorePoints $store): string => $store->storeCode, $points->stores))->toBe(['sa'])
        ->and($points->giveStoreCode)->toBe('ae');
});

it('reads no section for a customer the reader may not view, and refuses a reader with no points job', function () {
    $customer = screenReadsCustomer('egypt@example.test', 'Egypt', 'eg', 30, 'eg');
    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW, AccessPermissions::CUSTOMER_VIEW], ['sa']));

    expect(fn () => app(CustomerPointsInStoresHandler::class)->handle(new CustomerPointsInStores($customer)))->toThrow(PointsAccountNotFound::class);

    Fx::actAsStaff(Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['eg']));

    expect(fn () => app(CustomerPointsInStoresHandler::class)->handle(new CustomerPointsInStores($customer)))->toThrow(Unauthorized::class);
});

it('tells a screen whether the reader may change points and open the customer', function () {
    $customer = screenReadsCustomer('may@example.test', 'May', 'eg');
    // Resolved after each reader acts: it asks the request's authorizer, as a screen's action does.
    $actions = static fn (): LoyaltyActionsForReader => app(LoyaltyActionsForReader::class);

    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW], ['sa']));
    $may = $actions()->forCustomer($customer, StoreId::fromString(Fx::storeId('sa')));

    expect([$may->mayChangePoints, $may->mayOpenCustomer, $may->customer->name, $may->customer->email])->toBe([false, false, 'May Test', null]);

    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW, LoyaltyPermissions::ADJUST, AccessPermissions::CUSTOMER_VIEW], ['sa', 'eg'], RoleLevel::Admin));
    $may = $actions()->forCustomer($customer, StoreId::fromString(Fx::storeId('sa')));

    expect([$may->mayChangePoints, $may->mayOpenCustomer, $may->customer->email])->toBe([true, true, 'may@example.test']);
});

it('offers the Points tab while the programme is on, or while the customer has an account in the store', function () {
    $customer = screenReadsCustomer('tab@example.test', 'Tab');
    $offered = static fn (): bool => app(StoreContext::class)->runIn(StoreId::fromString(Fx::storeId('sa')), static fn (): bool => app(PointsTabOffered::class)->offers($customer));

    expect($offered())->toBeTrue();

    Lx::programme(settings: [ProgrammeSettings::ENABLED => false]);

    expect($offered())->toBeFalse();

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(AddPointsHandler::class)->handle(new AddPoints($customer, Fx::storeId('sa'), 5, 'Kept'));

    expect($offered())->toBeTrue();
});
