<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Sales's order screens in a real browser (sales.md §4.1 P1–P3). The feature tests beside these prove what the
| pages are handed and what each change does; these prove a person can use them: an order approved, paid in
| full, shipped and delivered from its page; the list, the order and the packing slip at a phone's width with
| nothing scrolling sideways.
|
| Deliberately no RefreshDatabase, as the other browser tests: every row here is new, from the clock.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    CatalogProducts::numberFrom((int) substr((string) hrtime(true), -8));
});

/** An order placed in 'sa', then the real ActorContext given back for the browser's requests. */
function salesOrderScreensOrder(): string
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        $ready = Cx::ready();

        return Cx::place(Cx::start($ready['address'])->id)->id;
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }
}

function salesOrderScreensPanel(): mixed
{
    $email = (string) DB::table('access.staff_users')->where('id', Fx::staff(superAdmin: true))->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();

    return $page;
}

function salesOrderScreensWide(mixed $page): string
{
    return (string) $page->script('document.documentElement.scrollWidth > document.documentElement.clientWidth ? String(document.documentElement.scrollWidth) : ""');
}

it('approves an order, records its whole payment, ships it and marks it delivered from its page', function () {
    $order = salesOrderScreensOrder();
    $page = salesOrderScreensPanel();

    $page->navigate("/admin/orders/{$order}", BROWSER_PAGE_LOAD)->click('[data-test="approve"]');
    expect(browserUntil($page, "document.body.innerText.includes('Order approved.')"))->toBeTrue();

    $page->click('[data-test="record-payment"]')
        ->assertPresent('[data-test="amount-owed"]')
        ->click('[data-test="confirm-payment"]');
    expect(browserUntil($page, "document.body.innerText.includes('Payment recorded.')"))->toBeTrue();

    $page->click('[data-test="ship"]')
        ->type('#carrier', 'SMSA')
        ->click('[data-test="confirm-ship"]');
    expect(browserUntil($page, "document.body.innerText.includes('Order shipped.')"))->toBeTrue();

    $page->click('[data-test="deliver"]');
    expect(browserUntil($page, "document.body.innerText.includes('Order marked delivered.')"))->toBeTrue()
        ->and(DB::table('sales.orders')->where('id', $order)->value('status'))->toBe('COMPLETED');
});

it('draws the list, an order and its packing slip at a phone\'s width with nothing scrolling sideways', function () {
    $order = salesOrderScreensOrder();
    $number = (string) DB::table('sales.orders')->where('id', $order)->value('number');
    $page = salesOrderScreensPanel();
    $page->resize(375, 812);

    $page->navigate('/admin/orders', BROWSER_PAGE_LOAD)->assertPresent("[data-test=\"order-card-{$number}\"]");
    expect(salesOrderScreensWide($page))->toBe('', 'the orders list scrolls sideways');

    foreach (["/admin/orders/{$order}", "/admin/orders/{$order}/print"] as $uri) {
        $page->navigate($uri, BROWSER_PAGE_LOAD)->assertSee($number);

        expect(salesOrderScreensWide($page))->toBe('', "{$uri} scrolls sideways");
    }
});
