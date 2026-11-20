<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicy;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicyHandler;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\MarkDelivered\MarkDelivered;
use Modules\Sales\Application\Command\MarkDelivered\MarkDeliveredHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\SalesPermissions;
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

// The browser suite keeps its data: the Saudi test region retired after each test, so later files may still
// type a Saudi region (CheckoutFixtures::unserve).
afterEach(fn () => Cx::unserve());

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

    // On a phone the cart is the tab bar's (content.md §1.5), drawn once the page is in the browser.
    expect(browserUntil($page, "document.querySelector('[data-test=\"tab-cart\"]') !== null"))->toBeTrue();
    $page->click('[data-test="tab-cart"]');
    expect(browserUntil($page, "location.pathname === '/sa/en/cart'"))->toBeTrue()
        ->and(salesShopWide($page))->toBe('', 'the cart scrolls sideways')
        // To the design on a phone (shop-design.md §7): no tab bar, Checkout pinned to the bottom with the subtotal before VAT.
        ->and($page->script("document.querySelector('[data-test=\"tab-bar\"]') === null"))->toBeTrue()
        ->and($page->script("getComputedStyle(document.querySelector('[data-test=\"checkout-row\"]')).position"))->toBe('fixed')
        ->and($page->script("Math.round(window.innerHeight - document.querySelector('[data-test=\"checkout-row\"]').getBoundingClientRect().bottom)"))->toBe(0)
        ->and($page->script("document.querySelector('[data-test=\"checkout-bar-subtotal\"]')?.textContent.includes('before VAT') === true"))->toBeTrue();

    // At a computer's width: the 34 px heading with the count of products, the 392 px summary, the 50 px Checkout, the line's chips.
    $page->resize(1440, 1000);
    expect(browserSoon($page, "getComputedStyle(document.querySelector('[data-test=\"cart-heading\"]')).fontSize === '34px'"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"cart-heading\"]').textContent"))->toContain('products')
        ->and($page->script("Math.round(document.querySelector('[data-test=\"cart-summary\"]').getBoundingClientRect().width)"))->toBe(392)
        // 50 px once the button's height transition from the phone's 48 px has run (it animates, `transition-all`).
        ->and(browserSoon($page, "Math.round(document.querySelector('[data-test=\"checkout\"]').getBoundingClientRect().height) === 50"))->toBeTrue('Checkout is not 50 px')
        ->and($page->script("document.querySelector('[data-test=\"line-mode\"]')?.textContent.trim()"))->toBe('Retail')
        ->and($page->script("document.querySelector('[data-test=\"cart-lines\"]').textContent.includes('per piece')"))->toBeTrue();
    $page->resize(375, 812);

    $page->click('[data-test="cart-lines"] li:first-child [data-test="more"]');
    expect(browserUntil($page, "document.querySelector('[data-test=\"cart-lines\"] li:first-child [data-test=\"quantity\"]')?.textContent === '3'"))->toBeTrue();

    $page->click('[data-test="checkout"]');
    expect(browserUntil($page, "location.pathname === '/sa/en/checkout'"))->toBeTrue()
        ->and(salesShopWide($page))->toBe('', 'checkout scrolls sideways');

    // The terms' tick above Place Order (flags-2026-11.md S3.3): the Terms and Conditions a link to its page in a new tab;
    // pressed unticked, it says so under the tick and nothing is placed.
    expect(browserSoon($page, "document.querySelector('[data-test=\"terms-link\"]')?.target === '_blank'"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"terms-link\"]').getAttribute('href')"))->toContain('/sa/en/policies/')
        ->and($page->script("document.querySelector('[data-test=\"terms-link\"]').textContent"))->toBe('Terms and Conditions')
        ->and($page->script("document.querySelector('[data-test=\"terms-sentence\"]').textContent.startsWith('I have read and agree to the Terms and Conditions')"))->toBeTrue();
    $page->click('[data-test="place-order"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"terms-error\"]')?.textContent === 'Tick the box to agree to the terms.'"))->toBeTrue()
        ->and($page->script('location.pathname'))->toBe('/sa/en/checkout')
        ->and(salesShopWide($page))->toBe('', 'checkout with the tick\'s message scrolls sideways');

    // In Arabic: the same sentence in its words, the link to the Arabic page.
    $page->navigate('/sa/ar/checkout', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"terms-link\"]')?.textContent === 'الشروط والأحكام'"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"terms-sentence\"]').textContent.startsWith('قرأتُ الشروط والأحكام')"))->toBeTrue()
        ->and($page->script("decodeURIComponent(document.querySelector('[data-test=\"terms-link\"]').getAttribute('href'))"))->toContain('/sa/ar/policies/')
        ->and(salesShopWide($page))->toBe('', 'the Arabic checkout scrolls sideways');
    $page->navigate('/sa/en/checkout', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"terms\"]') !== null"))->toBeTrue();

    $page->click('[data-test="terms"]');
    $page->click('[data-test="place-order"]');
    expect(browserUntil($page, "location.pathname.endsWith('/placed')"))->toBeTrue()
        ->and(salesShopWide($page))->toBe('', 'the order placed scrolls sideways');
    $page->assertSee('Order placed');

    expect((int) DB::table('sales.order_lines')->join('sales.orders', 'sales.orders.id', '=', 'sales.order_lines.order_id')
        ->where('sales.orders.customer_id', $ready['customer'])->where('sales.order_lines.variant_id', $ready['variants'][0])->value('sales.order_lines.quantity'))->toBe(3);
});

it('says a store without published terms takes no orders, in place of Place Order, at a phone\'s width in both languages', function () {
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        $ready = Cx::ready();
        // The store's terms taken down; the next checkout's fixtures publish new ones (CheckoutFixtures::terms).
        $terms = Cx::terms();
        Cx::asSuperAdmin(fn () => app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($terms, (int) DB::table('content.policies')->where('id', $terms)->value('version'))));
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

    $page->navigate('/sa/en/checkout', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"terms-missing\"]')?.textContent === 'Orders can\\'t be placed until this store\\'s terms are published.'"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"place-order\"]') === null && document.querySelector('[data-test=\"terms\"]') === null"))->toBeTrue()
        ->and(salesShopWide($page))->toBe('', 'checkout without terms scrolls sideways');

    $page->navigate('/sa/ar/checkout', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"terms-missing\"]')?.textContent === 'لا يمكن تقديم الطلبات حتى تُنشر شروط هذا المتجر.'"))->toBeTrue()
        ->and(salesShopWide($page))->toBe('', 'the Arabic checkout without terms scrolls sideways');
});

it('draws My Orders, an order, asking a return and the return at a phone\'s width with nothing scrolling sideways', function () {
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        $ready = Cx::ready();
        $placed = Cx::place(Cx::start($ready['address'])->id);
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL], ['sa']));
        app(ApproveOrderHandler::class)->handle(new ApproveOrder($placed->id));
        DB::table('sales.orders')->where('id', $placed->id)->update(['paid_minor' => DB::raw('due_minor')]);
        app(ShipOrderHandler::class)->handle(new ShipOrder($placed->id, otherCarrier: 'SMSA', trackingNumber: '123'));
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
        Cx::serve('sa', 25_00);
        Cx::freeForAll(300_00);
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
        // Its count of products: 2 lines (3 pieces), shop-design.md Q2.
        ->and($text($page, '[data-test="header-cart-count"]'))->toBe('2');

    // The goods, 250.00: 50.00 short of free shipping at 300.00; no fee before checkout (gap 6.6).
    $page->resize(375, 812);
    expect(browserUntil($page, "document.querySelector('[data-test=\"tab-cart\"]') !== null"))->toBeTrue();
    $page->click('[data-test="tab-cart"]');
    expect(browserUntil($page, "location.pathname === '/sa/en/cart'"))->toBeTrue()
        ->and($text($page, '[data-test="free-shipping"]'))->toContain('Add')->toContain('50.00')->toContain('more for free shipping.')
        ->and($text($page, '[data-test="summary-shipping"]'))->toContain('Worked out at checkout')
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
