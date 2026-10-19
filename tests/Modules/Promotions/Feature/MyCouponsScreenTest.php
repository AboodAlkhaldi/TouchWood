<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| The shop account's Coupons tab over HTTP (promotions.md §4.1 M5): always offered (owner's Q12), the
| coupons given to the customer that they can use now, and nobody else's.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    Queue::fake();
});

function myCouponsShopper(string $customerId): AdminBrowser
{
    $email = (string) DB::table('access.customers')->where('id', $customerId)->value('email');
    $browser = new AdminBrowser;
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    return $browser;
}

/**
 * @return array<string, mixed>
 */
function myCouponsProps(AdminBrowser $browser): array
{
    $props = [];

    $browser->get('/sa/en/account/coupons')->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props) {
        $page->component('Promotions/Storefront/Coupons');
        $props = $page->toArray()['props'];
    });

    return $props;
}

it('shows the customer the coupons given to them, and nobody else\'s', function () {
    $mine = Fx::customer('mine@example.test');
    $theirs = Fx::customer('theirs@example.test');
    $vip = Pr::coupon('VIP-20', with: ['eligibility' => 'ASSIGNED', 'percent' => 20, 'cap_minor' => 50_00, 'minimum_minor' => 300_00, 'max_uses_per_customer' => 2]);
    $other = Pr::coupon('OTHER-5', with: ['eligibility' => 'ASSIGNED', 'percent' => 5]);
    Pr::assign($vip, $mine);
    Pr::assign($other, $theirs);

    $props = myCouponsProps(myCouponsShopper($mine));

    expect(array_column($props['coupons'], 'code'))->toBe(['VIP-20'])
        ->and([$props['coupons'][0]['percent'], $props['coupons'][0]['cap'], $props['coupons'][0]['minimum'], $props['coupons'][0]['usesLeft']])
        ->toBe([20, ['amount' => '50.00', 'currency' => 'SAR'], ['amount' => '300.00', 'currency' => 'SAR'], 2]);
});

it('is always offered in the account menu, empty when there are none', function () {
    $shopper = myCouponsShopper(Fx::customer('none@example.test'));

    $shopper->get('/sa/en')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('accountMenu.pages', fn (Collection $pages): bool => $pages->contains('key', 'promotions.coupons')));

    expect(myCouponsProps($shopper)['coupons'])->toBe([]);
});

it('sends a visitor who is not signed in to the store home', function () {
    (new AdminBrowser)->get('/sa/en/account/coupons')->assertRedirect();
});

it('reads the tab in a fixed few queries of its own', function () {
    $customer = Fx::customer('count@example.test');
    Pr::assign(Pr::coupon('VIP-1', with: ['eligibility' => 'ASSIGNED']), $customer);
    $browser = myCouponsShopper($customer);
    $browser->get('/sa/en/account/coupons')->assertOk();
    /** @var ArrayObject<int, string> $own */
    $own = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($own): void {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (str_contains(str_replace(chr(92), '/', $frame['file'] ?? ''), '/src/Modules/Promotions/')) {
                $own[] = $query->sql;

                return;
            }
        }
    });

    $browser->get('/sa/en/account/coupons')->assertOk();

    expect(count($own))->toBe(1);
});
