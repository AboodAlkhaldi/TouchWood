<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\MarkDelivered\MarkDelivered;
use Modules\Sales\Application\Command\MarkDelivered\MarkDeliveredHandler;
use Modules\Sales\Application\Command\RequestReturn\RequestReturn;
use Modules\Sales\Application\Command\RequestReturn\RequestReturnHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\SalesPermissions;
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
| full, shipped and delivered from its page; a return accepted, received and completed, its refund recorded; the
| lists, an order, its packing slip and a return at a phone's width with nothing scrolling sideways.
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

    $page->navigate('/admin/orders', BROWSER_PAGE_LOAD)->assertPresent("[data-test=\"order-card-{$number}\"]")->assertPresent('[data-test="order-search"]');
    expect(salesOrderScreensWide($page))->toBe('', 'the orders list scrolls sideways');

    foreach (["/admin/orders/{$order}", "/admin/orders/{$order}/print"] as $uri) {
        $page->navigate($uri, BROWSER_PAGE_LOAD)->assertSee($number);

        expect(salesOrderScreensWide($page))->toBe('', "{$uri} scrolls sideways");
    }
});

it('accepts, receives and completes a return, records its refund, and draws it at a phone\'s width', function () {
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        $ready = Cx::ready();
        $order = Cx::place(Cx::start($ready['address'])->id)->id;
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL], ['sa']));
        app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
        app(ShipOrderHandler::class)->handle(new ShipOrder($order, 'SMSA', null));
        app(MarkDeliveredHandler::class)->handle(new MarkDelivered($order));
        Fx::actAsCustomer($ready['customer']);
        $line = (string) DB::table('sales.order_lines')->where('order_id', $order)->where('is_gift', false)->value('id');
        $return = app(RequestReturnHandler::class)->handle(new RequestReturn($order, 'DAMAGED', null, [['orderLineId' => $line, 'quantity' => 1]]));
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }

    $page = salesOrderScreensPanel();
    $page->resize(375, 812);
    $page->navigate("/admin/returns/{$return}", BROWSER_PAGE_LOAD)->click('[data-test="accept"]');
    expect(browserUntil($page, "document.body.innerText.includes('Return accepted.')"))->toBeTrue();
    $page->click('[data-test="receive"]')->click('[data-test="confirm-receive"]');
    expect(browserUntil($page, "document.body.innerText.includes('Return marked received.')"))->toBeTrue();
    $page->click('[data-test="complete"]')->click('[data-test="confirm-complete"]');
    expect(browserUntil($page, "document.body.innerText.includes('Record the refund.')"))->toBeTrue()
        ->and(salesOrderScreensWide($page))->toBe('', 'a return scrolls sideways');
    $page->click('[data-test="record-refund"]')->click('[data-test="confirm-refund"]');
    expect(browserUntil($page, "document.body.innerText.includes('Refund recorded.')"))->toBeTrue();

    $page->navigate('/admin/returns?tab=COMPLETED', BROWSER_PAGE_LOAD)->assertPresent("[data-test=\"return-card-{$return}\"]");
    expect(salesOrderScreensWide($page))->toBe('', 'the returns list scrolls sideways');
});

it('edits an order from its page: a piece more, Preview, a reason, Confirm, at a phone\'s width', function () {
    $order = salesOrderScreensOrder();
    $page = salesOrderScreensPanel();
    $page->resize(375, 812);

    $page->navigate("/admin/orders/{$order}/edit", BROWSER_PAGE_LOAD)->assertPresent('[data-test="edit-lines"]');
    expect(salesOrderScreensWide($page))->toBe('', 'the edit page scrolls sideways');

    $page->click('[data-test="edit-lines"] li:first-child [data-test="more"]')
        ->click('[data-test="preview-edit"]');
    expect(browserUntil($page, "document.querySelector('[data-test=\"preview-money\"]') !== null"))->toBeTrue();
    $page->type('#edit-reason', 'One more piece')
        ->click('[data-test="confirm-edit"]');
    expect(browserUntil($page, "document.body.innerText.includes('Order edited.')"))->toBeTrue()
        ->and(DB::table('sales.order_edits')->where('order_id', $order)->count())->toBe(1);
});
