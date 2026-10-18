<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Loyalty\Application\Command\AddPoints\AddPoints;
use Modules\Loyalty\Application\Command\AddPoints\AddPointsHandler;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Settings\ProgrammeSettings;
use Shared\Application\ActorContext;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;

use function Pest\Laravel\seed;

/*
| Loyalty's screens over HTTP (loyalty.md §4.1): Points › Customers (L1), a customer's points (L2) and
| the change made by hand (L3), Points › Redemptions (L4), the customer page's Points section (L5),
| Points › Settings (L6) and the shop account's Points tab (L7) - what each page is given, who is
| refused, and where each refusal lands. The reads have their own suite (LoyaltyScreenReadsTest).
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
    Lx::standInForSales();
    Lx::programme();
    Lx::programme('eg');
    // The real clock: the panel's sign-in code is checked against it.
});

/**
 * Signed in through the panel's door as a staff member holding these jobs in these stores.
 *
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function loyaltyScreens(array $permissions, array $stores = ['sa'], RoleLevel $level = RoleLevel::Staff): AdminBrowser
{
    $staffId = Fx::staffWith($permissions, $stores, $level);
    $browser = new AdminBrowser('10.9.1.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/** A customer of a store, named, with points given by hand there. */
function loyaltyScreensCustomer(string $email, string $first, string $storeCode = 'sa', int $points = 0): string
{
    $customer = Fx::customer($email, $storeCode);
    DB::table('access.customers')->where('id', $customer)->update(['first_name' => $first, 'last_name' => 'Screen']);

    if ($points > 0) {
        loyaltyScreensGive($customer, $points);
    }

    return $customer;
}

/**
 * Points given by hand as a Super Admin - a change by hand is always a staff member's, never the
 * system's - then the real ActorContext given back, so the requests after it read who is acting from
 * their own session, as AccessFixtures::customer() does.
 */
function loyaltyScreensGive(string $customer, int $points): void
{
    loyaltyScreensAside(function () use ($customer, $points): void {
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        app(AddPointsHandler::class)->handle(new AddPoints($customer, Fx::storeId('sa'), $points, 'Goodwill'));
    });
}

/**
 * What a page was given.
 *
 * @return array<string, mixed>
 */
function loyaltyScreensProps(AdminBrowser $browser, string $uri, string $component): array
{
    $props = [];

    $browser->get($uri)->assertOk()->assertInertia(function (AssertableInertia $page) use ($component, &$props) {
        $page->component($component);
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * The page's own queries, opened a second time (warm), the panel's frame not counted (frontend.md §5).
 */
function loyaltyScreensOwnQueries(AdminBrowser $browser, string $uri): int
{
    $browser->get($uri)->assertOk();
    /** @var ArrayObject<int, string> $own */
    $own = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($own): void {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (str_contains(str_replace(chr(92), '/', $frame['file'] ?? ''), '/src/Modules/Loyalty/')) {
                $own[] = $query->sql;

                return;
            }
        }
    });

    $browser->get($uri)->assertOk();

    return count($own);
}

/**
 * A field's error a redirect carries, as AdminBrowser::formError() reads the form's.
 *
 * @param  TestResponse<Response>  $response
 */
function loyaltyScreensFieldError(TestResponse $response, string $field): ?string
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->first($field) === '' ? null : $errors->first($field);
    }

    $message = is_array($errors) ? ($errors['default']['messages'][$field][0] ?? null) : null;

    return is_string($message) ? $message : null;
}

/**
 * Work done through another actor - Sales's stand-in, placing and delivering - then the real
 * ActorContext given back, so the requests after it read who is acting from their own session.
 */
function loyaltyScreensAside(callable $work): mixed
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        return $work();
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }
}

/** A browser signed in to the shop as this customer. */
function loyaltyScreensShopper(string $customerId): AdminBrowser
{
    $email = (string) DB::table('access.customers')->where('id', $customerId)->value('email');
    $browser = new AdminBrowser;
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    return $browser;
}

describe('L1 Points › Customers', function () {
    it('lists the store\'s holders, most points first, with the store, its filter and the programme\'s state', function () {
        loyaltyScreensCustomer('a@example.test', 'Amal', points: 100);
        loyaltyScreensCustomer('b@example.test', 'Badr', points: 900);

        $props = loyaltyScreensProps(loyaltyScreens([LoyaltyPermissions::VIEW], ['sa', 'eg']), '/admin/points/customers', 'Loyalty/Admin/Points/Customers');

        expect(array_map(static fn (array $row): array => [$row['customer']['name'], $row['points'], $row['worth']], $props['holders']))
            ->toBe([['Badr Screen', 900, ['amount' => '9.00', 'currency' => 'SAR']], ['Amal Screen', 100, ['amount' => '1.00', 'currency' => 'SAR']]])
            ->and([$props['storeCode'], $props['programmeOn'], $props['tooMany'], $props['nextAccountId']])->toBe(['sa', true, false, null])
            ->and(array_column($props['stores'], 'code'))->toBe(['sa', 'eg']);
    });

    it('pages with Show More\'s cursor, and searches by name', function () {
        foreach (range(1, 3) as $n) {
            loyaltyScreensCustomer("p{$n}@example.test", "Page{$n}", points: $n * 100);
        }

        $browser = loyaltyScreens([LoyaltyPermissions::VIEW]);
        $first = loyaltyScreensProps($browser, '/admin/points/customers', 'Loyalty/Admin/Points/Customers');

        expect($first['holders'])->toHaveCount(3);

        $found = loyaltyScreensProps($browser, '/admin/points/customers?search=page2', 'Loyalty/Admin/Points/Customers');

        expect(array_column(array_column($found['holders'], 'customer'), 'name'))->toBe(['Page2 Screen'])
            ->and($found['search'])->toBe('page2');

        $last = $first['holders'][1];
        $more = loyaltyScreensProps($browser, '/admin/points/customers?after_points='.$last['points'].'&after_account='.$last['accountId'], 'Loyalty/Admin/Points/Customers');

        expect(array_column($more['holders'], 'points'))->toBe([100]);
    });

    it('says when the programme is off, and refuses a reader with no points job or a store not offered', function () {
        Lx::programme(settings: [ProgrammeSettings::ENABLED => false]);

        expect(loyaltyScreensProps(loyaltyScreens([LoyaltyPermissions::VIEW]), '/admin/points/customers', 'Loyalty/Admin/Points/Customers')['programmeOn'])->toBeFalse();

        loyaltyScreens([AccessPermissions::CUSTOMER_VIEW])->get('/admin/points/customers')->assertForbidden();
        loyaltyScreens([LoyaltyPermissions::VIEW])->get('/admin/points/customers?store=eg')->assertForbidden();
    });

});

describe('the query budget (frontend.md §5: 15 an admin page, its own; the count recorded)', function () {
    it('reads each Points page in a fixed few queries, the same for one row as for six', function () {
        $customer = loyaltyScreensCustomer('q0@example.test', 'Query0', points: 10);
        $pages = ['/admin/points/customers', "/admin/points/customers/{$customer}", '/admin/points/redemptions', "/admin/customers/{$customer}"];
        // Each count from a reader signed in after the rows are there: rows made by hand in the
        // middle of a session would make it someone else's.
        $counts = static function () use ($pages): array {
            $browser = loyaltyScreens([LoyaltyPermissions::VIEW, AccessPermissions::CUSTOMER_VIEW]);

            return array_map(static fn (string $uri): int => loyaltyScreensOwnQueries($browser, $uri), $pages);
        };
        $one = $counts();

        foreach (range(1, 5) as $n) {
            loyaltyScreensCustomer("q{$n}@example.test", "Query{$n}", points: $n * 10);
            loyaltyScreensGive($customer, $n);
        }

        // Recorded: a page that grows fails here, even under the ceiling (frontend.md §5).
        expect($one)->toBe([5, 8, 3, 3])
            ->and($counts())->toBe($one)
            ->and(max($one))->toBeLessThanOrEqual(15);
    });
});

describe('L2 a customer\'s points and L3 the change by hand', function () {
    it('gives the page the balance, the history and what this reader may do', function () {
        $customer = loyaltyScreensCustomer('c@example.test', 'Carim', points: 250);

        $staff = loyaltyScreensProps(loyaltyScreens([LoyaltyPermissions::VIEW]), "/admin/points/customers/{$customer}", 'Loyalty/Admin/Points/Customer');

        expect([$staff['points'], $staff['customer']['name'], $staff['mayChangePoints'], $staff['mayOpenCustomer'], count($staff['history']), $staff['history'][0]['kind']])
            ->toBe([250, 'Carim Screen', false, false, 1, 'ADDED'])
            ->and($staff['expiring']['points'])->toBe(250)
            // A year on, in Riyadh's calendar (the store's zone).
            ->and($staff['expiring']['day'])->toBe(now('Asia/Riyadh')->addYear()->format('Y-m-d'));

        $admin = loyaltyScreensProps(loyaltyScreens([LoyaltyPermissions::VIEW, LoyaltyPermissions::ADJUST, AccessPermissions::CUSTOMER_VIEW], level: RoleLevel::Admin), "/admin/points/customers/{$customer}", 'Loyalty/Admin/Points/Customer');

        expect([$admin['mayChangePoints'], $admin['mayOpenCustomer'], $admin['mostByHand']])->toBe([true, true, 1_000_000_000]);
    });

    it('answers a customer with no points here, whom the reader may not view, as not found', function () {
        $customer = loyaltyScreensCustomer('n@example.test', 'Nada');

        loyaltyScreens([LoyaltyPermissions::VIEW])->get("/admin/points/customers/{$customer}")->assertNotFound();
        loyaltyScreens([LoyaltyPermissions::VIEW])->get('/admin/points/customers/01k0000000000000000000000z')->assertNotFound();

        $props = loyaltyScreensProps(loyaltyScreens([LoyaltyPermissions::VIEW, AccessPermissions::CUSTOMER_VIEW]), "/admin/points/customers/{$customer}", 'Loyalty/Admin/Points/Customer');

        expect([$props['points'], $props['history'], $props['lots']])->toBe([0, [], []]);
    });

    it('adds and removes points by hand in the store shown, saying so, and keeps a refused change\'s message for the dialog', function () {
        $customer = loyaltyScreensCustomer('d@example.test', 'Dana', points: 100);
        $browser = loyaltyScreens([LoyaltyPermissions::VIEW, LoyaltyPermissions::ADJUST], level: RoleLevel::Admin);
        $balance = static fn (): int => (int) DB::table('loyalty.accounts')->where('customer_id', $customer)->value('balance');

        $added = $browser->post("/admin/points/customers/{$customer}/change", ['mode' => 'add', 'points' => '1200', 'reason' => 'Goodwill', 'store' => 'sa']);

        expect([$added->getStatusCode(), AdminBrowser::flashed($added, 'status'), $balance()])->toBe([302, 'Points added: 1,200.', 1300]);

        $removed = $browser->post("/admin/points/customers/{$customer}/change", ['mode' => 'remove', 'points' => '300', 'reason' => 'Correction', 'store' => 'sa']);

        expect([AdminBrowser::flashed($removed, 'status'), $balance()])->toBe(['Points removed: 300.', 1000]);

        $tooMany = $browser->post("/admin/points/customers/{$customer}/change", ['mode' => 'remove', 'points' => '5000', 'reason' => 'Correction', 'store' => 'sa']);

        expect(AdminBrowser::formError($tooMany))->not->toBeNull()
            ->and($balance())->toBe(1000);
    });

    it('refuses what is not a whole number of points, a mode that is neither, and a reader without the job', function () {
        $customer = loyaltyScreensCustomer('e@example.test', 'Eman', points: 100);
        $admin = loyaltyScreens([LoyaltyPermissions::VIEW, LoyaltyPermissions::ADJUST], level: RoleLevel::Admin);

        expect(loyaltyScreensFieldError($admin->post("/admin/points/customers/{$customer}/change", ['mode' => 'add', 'points' => '1.5', 'reason' => 'x', 'store' => 'sa']), 'points'))->toBe('Points are a whole number, 1 or more.')
            ->and(AdminBrowser::formError($admin->post("/admin/points/customers/{$customer}/change", ['mode' => 'give', 'points' => '5', 'reason' => 'x', 'store' => 'sa'])))->toBe('Choose Add or Remove.');

        $staff = loyaltyScreens([LoyaltyPermissions::VIEW]);
        $refused = $staff->post("/admin/points/customers/{$customer}/change", ['mode' => 'add', 'points' => '5', 'reason' => 'x', 'store' => 'sa']);

        expect(AdminBrowser::formError($refused))->not->toBeNull()
            ->and((int) DB::table('loyalty.accounts')->where('customer_id', $customer)->value('balance'))->toBe(100);
    });
});

describe('L4 Points › Redemptions', function () {
    it('lists the store\'s redemptions with each customer\'s name, newest first', function () {
        $customer = loyaltyScreensCustomer('r@example.test', 'Rana');
        loyaltyScreensAside(function () use ($customer): void {
            Lx::deliver($customer, 100_000);
            Lx::place($customer, Lx::quote($customer, 100, 100_000), 100_000);
        });

        $props = loyaltyScreensProps(loyaltyScreens([LoyaltyPermissions::VIEW]), '/admin/points/redemptions', 'Loyalty/Admin/Points/Redemptions');

        expect(array_map(static fn (array $row): array => [$row['customer']['name'], $row['customer']['email'], $row['points'], $row['discount']], $props['redemptions']))
            ->toBe([['Rana Screen', null, 100, ['amount' => '1.00', 'currency' => 'SAR']]]);
    });
});

describe('L5 the customer page\'s section and L6 Settings', function () {
    it('puts a block per store on the customer page for a reader who reads points, and nothing for one who does not', function () {
        $customer = loyaltyScreensCustomer('s@example.test', 'Sami', points: 70);

        $props = loyaltyScreensProps(loyaltyScreens([AccessPermissions::CUSTOMER_VIEW, LoyaltyPermissions::VIEW]), "/admin/customers/{$customer}", 'Access/Admin/Customers/Show');

        expect($props['sections'])->toHaveCount(1)
            ->and([$props['sections'][0]['module'], $props['sections'][0]['key']])->toBe(['loyalty', 'points'])
            ->and(array_map(static fn (array $store): array => [$store['code'], $store['points'], $store['mayOpen']], $props['sections'][0]['data']['stores']))->toBe([['sa', 70, true]])
            ->and($props['translations'])->toHaveKey('loyalty::admin_points.section.title');

        expect(loyaltyScreensProps(loyaltyScreens([AccessPermissions::CUSTOMER_VIEW]), "/admin/customers/{$customer}", 'Access/Admin/Customers/Show')['sections'])->toBe([]);
    });

    it('opens Platform\'s settings screen at the Points card, for the same store', function () {
        loyaltyScreens([LoyaltyPermissions::SETTINGS_UPDATE], level: RoleLevel::Admin)->get('/admin/points/settings?store=sa')
            ->assertRedirect('/admin/settings?store=sa#settings-loyalty');
    });
});

describe('L7 the shop account\'s Points tab', function () {
    it('shows a customer their points while the programme is on, with the rates for the line that says how it works', function () {
        $customer = loyaltyScreensCustomer('t@example.test', 'Tala', points: 40);
        $props = [];

        loyaltyScreensShopper($customer)->get('/sa/en/account/points')->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props) {
            $page->component('Loyalty/Storefront/Points');
            $props = $page->toArray()['props'];
        });

        expect([$props['points'], $props['programmeOn'], $props['earnPerUnit'], $props['pointsPerUnitOff'], $props['unit']])->toBe([40, true, 1, 100, ['amount' => '1.00', 'currency' => 'SAR']])
            // Never a reason or an admin's name to the customer.
            ->and([$props['history'][0]['reason'], $props['history'][0]['staffName']])->toBe([null, null])
            ->and(array_column($props['accountMenu']['pages'], 'key'))->toContain('loyalty.points');
    });

    it('is not offered, and not there, while the programme is off and the customer has no points in the store', function () {
        Lx::programme(settings: [ProgrammeSettings::ENABLED => false]);
        $customer = loyaltyScreensCustomer('u@example.test', 'Uma');
        $shopper = loyaltyScreensShopper($customer);

        $shopper->get('/sa/en/account/points')->assertNotFound();
        $shopper->get('/sa/en')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('accountMenu.pages', fn (Collection $pages): bool => ! $pages->contains('key', 'loyalty.points')));

        loyaltyScreensGive($customer, 5);

        $shopper->get('/sa/en/account/points')->assertOk();
    });
});
