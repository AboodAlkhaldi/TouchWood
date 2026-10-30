<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\MarkDelivered\MarkDelivered;
use Modules\Sales\Application\Command\MarkDelivered\MarkDeliveredHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Application\SalesSettings;
use Modules\Sales\Domain\Model\RemovedLine;
use Modules\Sales\Domain\Repository\Carts;
use Shared\Application\ActorContext;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
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

    $page->navigate("/sa/en/account/orders/{$placed->number}", BROWSER_PAGE_LOAD)->assertPresent('[data-test="timeline"]')
        // The number the courier calls, labelled, as the order was placed (access.md amendment 89).
        ->assertSeeIn('[data-test="order-phone"]', 'Account phone')
        ->assertSeeIn('[data-test="order-phone"]', (string) DB::table('sales.orders')->where('id', $placed->id)->value('customer_phone'));
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

it('draws the cart\'s shipping, how much more ships free and the header\'s pieces; on the product page, the pieces in the cart and Out of stock', function () {
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        $ready = Cx::ready();
        Cx::setting(SalesSettings::FLAT_FEE, 25_00);
        Cx::setting(SalesSettings::FREE_ABOVE, 300_00);
        // A line that left at a sign-in, for the notice's Close (R2-8; B17).
        $cart = app(Carts::class)->find(StoreId::fromString(Fx::storeId('sa')), $ready['customer'], null) ?? throw new LogicException('No cart.');
        app(Carts::class)->setNotice($cart->id, [new RemovedLine($ready['variants'][1], SaleMode::Wholesale, 3, RemovedLine::WHOLESALE)], CarbonImmutable::now());
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }

    $email = (string) DB::table('access.customers')->where('id', $ready['customer'])->value('email');
    $text = static fn (mixed $page, string $selector): string => (string) $page->script("document.querySelector('{$selector}')?.textContent ?? ''");
    $page = visit('/sa/en/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::CUSTOMER_PASSWORD)
        ->click('button[type="submit"]');
    expect(browserUntil($page, "location.pathname === '/sa/en'"))->toBeTrue()
        ->and($text($page, '[data-test="header-cart-pieces"]'))->toBe('3');

    // The goods, 250.00: 50.00 short of free shipping at 300.00; the fee 25.00 in the summary.
    $page->resize(375, 812);
    $page->click('[data-test="header-cart"]');
    expect(browserUntil($page, "location.pathname === '/sa/en/cart'"))->toBeTrue()
        ->and($text($page, '[data-test="free-shipping"]'))->toContain('Add')->toContain('50.00')->toContain('more for free shipping.')
        ->and($text($page, '[data-test="summary-shipping"]'))->toContain('Shipping')->toContain('25.00')->toContain('free above')->toContain('300.00')
        ->and(salesShopWide($page))->toBe('', 'the cart scrolls sideways');

    // Closed, the notice stays away: the cart again has none.
    $page->click('[data-test="close-notice"]');
    expect(browserUntil($page, "!document.querySelector('[data-test=\"cart-notice\"]')"))->toBeTrue();

    // The page hides it at once; the server's answer comes after - waited for before the cart is asked again.
    for ($tries = 0; $tries < 50 && DB::table('sales.carts')->where('id', $cart->id)->value('notice') !== null; $tries++) {
        usleep(100_000);
    }

    expect(DB::table('sales.carts')->where('id', $cart->id)->value('notice'))->toBeNull();
    $page->navigate('/sa/en/cart');
    expect(browserUntil($page, "document.querySelector('[data-test=\"cart-summary\"]') !== null && !document.querySelector('[data-test=\"cart-notice\"]')"))->toBeTrue();

    // Its first size: 2 in the cart. Then only those 2 left in stock: none more can be added.
    $slug = (string) DB::table('catalog.product_slugs')->where('product_id', $ready['product'])->where('locale', 'en')->where('is_current', true)->value('slug');
    $page->navigate('/sa/en/products/'.$slug);
    expect(browserUntil($page, "document.querySelector('[data-test=\"in-cart\"]')?.textContent === '2 pieces in your cart'"))->toBeTrue();

    try {
        Ix::count('sa', [$ready['variants'][0] => 2]);
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }

    $page->navigate('/sa/en/products/'.$slug);
    expect(browserUntil($page, "document.querySelector('[data-test=\"out-of-stock\"]')?.textContent === 'Out of stock'"))->toBeTrue()
        ->and(salesShopWide($page))->toBe('', 'the product page scrolls sideways');
});
