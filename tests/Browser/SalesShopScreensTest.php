<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\MarkDelivered\MarkDelivered;
use Modules\Sales\Application\Command\MarkDelivered\MarkDeliveredHandler;
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
| Sales's shop screens in a real browser (sales.md §4.1 S3–S6). The feature tests beside these prove what the
| pages are handed and what each change does; this proves a customer can use them at a phone's width: a piece
| more from the cart, Checkout, Place Order, the order placed - nothing scrolling sideways on the way.
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

function salesShopWide(mixed $page): string
{
    return (string) $page->script('document.documentElement.scrollWidth > document.documentElement.clientWidth ? String(document.documentElement.scrollWidth) : ""');
}

it('adds a piece in the cart, checks out and places the order at a phone\'s width with nothing scrolling sideways', function () {
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        $ready = Cx::ready();
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }

    $email = (string) DB::table('access.customers')->where('id', $ready['customer'])->value('email');
    $page = visit('/sa/en/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::CUSTOMER_PASSWORD)
        ->click('button[type="submit"]');
    expect(browserUntil($page, "location.pathname === '/sa/en'"))->toBeTrue();
    $page->resize(375, 812);

    $page->click('[data-test="header-cart"]');
    expect(browserUntil($page, "location.pathname === '/sa/en/cart'"))->toBeTrue()
        ->and(salesShopWide($page))->toBe('', 'the cart scrolls sideways');

    $page->click('[data-test="cart-lines"] li:first-child [data-test="more"]');
    expect(browserUntil($page, "document.querySelector('[data-test=\"cart-lines\"] li:first-child [data-test=\"quantity\"]')?.textContent === '3'"))->toBeTrue();

    $page->click('[data-test="checkout"]');
    expect(browserUntil($page, "location.pathname === '/sa/en/checkout'"))->toBeTrue()
        ->and(salesShopWide($page))->toBe('', 'checkout scrolls sideways');

    $page->click('[data-test="place-order"]');
    expect(browserUntil($page, "location.pathname.endsWith('/placed')"))->toBeTrue()
        ->and(salesShopWide($page))->toBe('', 'the order placed scrolls sideways');
    $page->assertSee('Order placed');

    expect((int) DB::table('sales.order_lines')->join('sales.orders', 'sales.orders.id', '=', 'sales.order_lines.order_id')
        ->where('sales.orders.customer_id', $ready['customer'])->where('sales.order_lines.variant_id', $ready['variants'][0])->value('sales.order_lines.quantity'))->toBe(3);
});

it('draws My Orders, an order, asking a return and the return at a phone\'s width with nothing scrolling sideways', function () {
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        $ready = Cx::ready();
        $placed = Cx::place(Cx::start($ready['address'])->id);
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL], ['sa']));
        app(ApproveOrderHandler::class)->handle(new ApproveOrder($placed->id));
        DB::table('sales.orders')->where('id', $placed->id)->update(['paid_minor' => DB::raw('due_minor')]);
        app(ShipOrderHandler::class)->handle(new ShipOrder($placed->id, 'SMSA', '123'));
        app(MarkDeliveredHandler::class)->handle(new MarkDelivered($placed->id));
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }

    $email = (string) DB::table('access.customers')->where('id', $ready['customer'])->value('email');
    $page = visit('/sa/en/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::CUSTOMER_PASSWORD)
        ->click('button[type="submit"]');
    expect(browserUntil($page, "location.pathname === '/sa/en'"))->toBeTrue();
    $page->resize(375, 812);

    $page->navigate('/sa/en/account/orders', BROWSER_PAGE_LOAD)->assertPresent("[data-test=\"my-order-{$placed->number}\"]");
    expect(salesShopWide($page))->toBe('', 'My Orders scrolls sideways');

    $page->navigate("/sa/en/account/orders/{$placed->number}", BROWSER_PAGE_LOAD)->assertPresent('[data-test="timeline"]');
    expect(salesShopWide($page))->toBe('', 'the order scrolls sideways');

    $page->click('[data-test="ask-return"]');
    expect(browserUntil($page, "location.pathname.endsWith('/return')"))->toBeTrue()
        ->and(salesShopWide($page))->toBe('', 'asking a return scrolls sideways');
    $page->click('[data-test="return-lines"] li:first-child [data-test="more"]')
        ->select('#return-reason', 'DAMAGED')
        ->click('[data-test="send-return"]');
    expect(browserUntil($page, "location.pathname.includes('/account/returns/')"))->toBeTrue()
        ->and(salesShopWide($page))->toBe('', 'the return scrolls sideways');
    $page->assertPresent('[data-test="withdraw"]');
});
