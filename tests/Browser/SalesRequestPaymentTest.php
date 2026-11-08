<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Sales\Application\Command\RequestOrderPayment\RequestOrderPayment;
use Modules\Sales\Application\Command\RequestOrderPayment\RequestOrderPaymentHandler;
use Modules\Sales\Application\Command\WithdrawOrderRequest\WithdrawOrderRequest;
use Modules\Sales\Application\Command\WithdrawOrderRequest\WithdrawOrderRequestHandler;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts;
use Tests\Modules\Payments\Support\FakeMyFatoorah;
use Tests\Modules\Payments\Support\FakePaymentPages;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| A payment request in a real browser (payments.md §4.2, Q11–Q13), at a phone's width and a desktop's, against a fake form and
| a fake bank page. The order's Note leads to the page; paid goes back to the order, saying so; a decline comes back to the
| form; a payment still open shows a note and no form; an ended link says so; a store closed meanwhile gets its one page.
|
| Deliberately no RefreshDatabase, as the other browser tests: what is made here goes again after each test.
*/

const SALES_REQUEST_FITS = 'document.documentElement.scrollWidth <= window.innerWidth';

afterEach(function () {
    DB::statement('TRUNCATE payments.transactions, payments.attempts, payments.requests, payments.sessions, payments.gateway_keys, payments.gateways CASCADE');
    DB::table('platform.stores')->where('code', 'eg')->update(['is_base' => false]);
    DB::table('platform.stores')->where('code', 'sa')->update(['is_base' => true, 'is_active' => true]);
    Fx::asSystem(fn () => app(StoreDirectory::class)->invalidate());
    app()->forgetScopedInstances();
    Cx::unserve();
});

beforeEach(function () {
    FakePaymentPages::install();
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    CatalogProducts::numberFrom((int) substr((string) hrtime(true), -8));
    app()->instance(KeyTester::class, new class implements KeyTester
    {
        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void {}
    });
});

/**
 * @return array{fake: FakeMyFatoorah, page: mixed, order: string, number: string, amount: string}
 */
function salesRequestReady(int $width = 375, int $height = 812, string $mode = 'LIVE'): array
{
    $fake = FakeMyFatoorah::install();
    $fake->framePath = FakePaymentPages::BANK;
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        Cx::asSuperAdmin(function () use ($mode): void {
            app(ChangeKeysHandler::class)->handle(new ChangeKeys(Fx::storeId('sa'), $mode, 'sk_key_ABC123', 'whsec_secret_1', '2030-12-31'));
            app(SwitchGatewayHandler::class)->handle(new SwitchGateway(Fx::storeId('sa'), true));
        });
        ['customer' => $customer, 'address' => $address] = Cx::ready();
        $placed = Cx::place(Cx::start($address)->id);
        Cx::asSuperAdmin(static fn () => app(RequestOrderPaymentHandler::class)->handle(new RequestOrderPayment($placed->id)));
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }

    $email = (string) DB::table('access.customers')->where('id', $customer)->value('email');
    // The framework loaded in this process first: a cold first page on a busy machine outlasts a browser's patience.
    get('/sa/en/sign-in');
    $page = visit('/sa/en/sign-in', ['timeout' => 20_000]);
    browserSoon($page, "!!document.querySelector('#email')");
    $page->type('#email', $email)->type('#password', Fx::CUSTOMER_PASSWORD)->click('button[type="submit"]');
    expect(browserSoon($page, "location.pathname === '/sa/en'"))->toBeTrue();
    $page->resize($width, $height);
    $minor = (int) DB::table('sales.orders')->where('id', $placed->id)->value('order_total_minor');

    return ['fake' => $fake, 'page' => $page, 'order' => $placed->id, 'number' => $placed->number, 'amount' => number_format($minor / 100, 2, '.', '')];
}

/** The request's page open, the form drawn, Pay pressed, the bank's page in its frame. */
function salesRequestPay(mixed $page, string $number): void
{
    $page->navigate("/sa/en/orders/{$number}/pay", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "!!document.querySelector('[data-test=\"fake-form\"]')"))->toBeTrue('the form never drew');
    $page->click('[data-test="pay"]');
    expect(browserSoon($page, "!!document.querySelector('[data-test=\"payment-frame\"]')"))->toBeTrue('the frame never opened')
        ->and(browserSoon($page, FakePaymentPages::BANK_READY))->toBeTrue('the bank page never loaded');
}

it('pays on a phone: from the order\'s Note to the page, then back to the order, saying so', function () {
    ['fake' => $fake, 'page' => $page, 'number' => $number, 'amount' => $amount] = salesRequestReady();

    $page->navigate('/sa/en/account/orders', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "!!document.querySelector('[data-test=\"payment-requested\"]')"))->toBeTrue()
        ->and(browserSoon($page, SALES_REQUEST_FITS))->toBeTrue('My Orders scrolls sideways');

    $page->navigate("/sa/en/account/orders/{$number}", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "(document.querySelector('[data-test=\"order-request\"]')?.textContent ?? '').includes('Pay the remaining')"))->toBeTrue()
        ->and(browserSoon($page, SALES_REQUEST_FITS))->toBeTrue('the order scrolls sideways');

    $page->click('[data-test="order-pay-now"]');
    expect(browserSoon($page, "location.pathname.endsWith('/pay') && !!document.querySelector('[data-test=\"fake-form\"]')"))->toBeTrue('the form never drew')
        ->and(browserSoon($page, SALES_REQUEST_FITS))->toBeTrue('the page scrolls sideways')
        ->and((string) $page->script("document.querySelector('[data-test=\"pay\"]').textContent"))->toContain('Pay ·')
        ->and((string) $page->script("document.querySelector('[data-test=\"request-amount\"]').textContent"))->toContain(number_format((float) $amount, 2))
        ->and((string) $page->script("document.querySelector('[data-test=\"request-until\"]').textContent"))->toMatch('/Open until .*\d\d:\d\d/');

    $page->click('[data-test="pay"]');
    expect(browserSoon($page, FakePaymentPages::BANK_READY))->toBeTrue('the bank page never loaded')
        ->and(browserSoon($page, SALES_REQUEST_FITS))->toBeTrue('the frame scrolls the page sideways');

    $fake->paid($amount);
    $page->script(FakePaymentPages::FINISH);

    expect(browserSoon($page, "location.pathname.endsWith('/account/orders/{$number}')"))->toBeTrue('it never went back to the order')
        ->and(browserSoon($page, "document.body.textContent.includes('Payment received.')"))->toBeTrue('the order never said so')
        ->and((bool) $page->script("!document.querySelector('[data-test=\"order-request\"]')"))->toBeTrue();
});

it('comes back from a decline to the form, with the reason, and Pay again', function () {
    ['fake' => $fake, 'page' => $page, 'number' => $number] = salesRequestReady(1280, 800);
    salesRequestPay($page, $number);

    $fake->declined('MF004');
    $page->script(FakePaymentPages::FINISH);

    expect(browserSoon($page, "(document.querySelector('[data-test=\"payment-notice\"]')?.textContent ?? '').includes('No money was taken')"))->toBeTrue()
        ->and(browserSoon($page, "!!document.querySelector('[data-test=\"fake-form\"]') && document.querySelector('[data-test=\"pay\"]').getAttribute('aria-disabled') === null"))->toBeTrue('Pay never came back');
});

it('shows a payment still open as a note and no form, and comes back by itself', function () {
    ['fake' => $fake, 'page' => $page, 'number' => $number] = salesRequestReady();
    salesRequestPay($page, $number);

    $page->navigate("/sa/en/orders/{$number}/pay", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "(document.querySelector('[data-test=\"payment-in-the-way\"]')?.textContent ?? '').includes('can still go through')"))->toBeTrue()
        ->and((bool) $page->script("!document.querySelector('[data-test=\"payment-block\"]')"))->toBeTrue()
        ->and(browserSoon($page, SALES_REQUEST_FITS))->toBeTrue('the note scrolls the page sideways');

    $page->script('window.notReloaded = true');
    $fake->declined('MF004');
    // The note's own ask, past the 10 seconds the server keeps an answer.
    sleep(16);

    expect(browserSoon($page, "!document.querySelector('[data-test=\"payment-in-the-way\"]') && !!document.querySelector('[data-test=\"fake-form\"]')"))->toBeTrue('the form never came back')
        ->and((bool) $page->script('window.notReloaded === true'))->toBeTrue();
});

it('says an ended link has ended, with a way to the order', function () {
    ['page' => $page, 'order' => $order, 'number' => $number] = salesRequestReady();
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
    Cx::asSuperAdmin(static fn () => app(WithdrawOrderRequestHandler::class)->handle(new WithdrawOrderRequest($order)));
    app()->scoped(ActorContext::class, $previous);
    app()->forgetScopedInstances();

    $page->navigate("/sa/en/orders/{$number}/pay", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "(document.querySelector('[data-test=\"request-ended\"]')?.textContent ?? '').includes('This payment link has ended.')"))->toBeTrue()
        ->and((bool) $page->script("!document.querySelector('[data-test=\"pay\"]')"))->toBeTrue()
        ->and(browserSoon($page, SALES_REQUEST_FITS))->toBeTrue('the ended page scrolls sideways');

    $page->click('[data-test="request-to-order"]');
    expect(browserSoon($page, "location.pathname.endsWith('/account/orders/{$number}')"))->toBeTrue();
});

it('says Test mode on a test order, and its form still opens', function () {
    ['page' => $page, 'number' => $number] = salesRequestReady(1280, 800, 'TEST');

    $page->navigate("/sa/en/orders/{$number}/pay", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "(document.querySelector('[data-test=\"request-test-note\"]')?.textContent ?? '').includes('no real money')"))->toBeTrue()
        ->and(browserSoon($page, "!!document.querySelector('[data-test=\"fake-form\"]')"))->toBeTrue();
});

it('shows a store closed while paying its one page', function () {
    ['fake' => $fake, 'page' => $page, 'number' => $number, 'amount' => $amount] = salesRequestReady();
    salesRequestPay($page, $number);

    $fake->paid($amount);
    DB::table('platform.stores')->where('code', 'sa')->update(['is_base' => false, 'is_active' => false]);
    DB::table('platform.stores')->where('code', 'eg')->update(['is_base' => true]);
    Fx::asSystem(fn () => app(StoreDirectory::class)->invalidate());
    app()->forgetScopedInstances();
    $page->script(FakePaymentPages::FINISH);

    expect(browserSoon($page, "!!document.querySelector('[data-test=\"payment-store-closed\"]')"))->toBeTrue('the closed page never came')
        ->and(browserSoon($page, "!!document.querySelector('[data-test=\"payment-main-store\"]')"))->toBeTrue();
});

it('keeps the page working when the payment code can\'t load, in the page\'s own words', function () {
    ['page' => $page, 'number' => $number] = salesRequestReady(1280, 800);
    config(['testing.payment_script_down' => true]);

    $page->navigate("/sa/en/orders/{$number}/pay", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "(document.querySelector('[data-test=\"payment-failed\"]')?.textContent ?? '').includes('Try again')"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('[data-test=\"payment-failed\"]').textContent"))->not->toContain('Staff will contact you')
        ->and((string) $page->script("document.querySelector('[data-test=\"pay\"]').getAttribute('aria-disabled')"))->toBe('true')
        ->and((bool) $page->script("!!document.querySelector('[data-test=\"request-order\"]')"))->toBeTrue();
});

it('says the link has ended in the form\'s place once the gateway has left the request\'s mode, without reloading', function () {
    ['page' => $page, 'number' => $number] = salesRequestReady();
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
    Cx::asSuperAdmin(static fn () => app(ChangeKeysHandler::class)->handle(new ChangeKeys(Fx::storeId('sa'), 'TEST', 'sk_key_TEST12', 'whsec_secret_2', '2030-12-31')));
    app()->scoped(ActorContext::class, $previous);
    app()->forgetScopedInstances();
    DB::table('payments.gateways')->update(['mode' => 'TEST']);

    $page->navigate("/sa/en/orders/{$number}/pay", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "(document.querySelector('[data-test=\"payment-failed\"]')?.textContent ?? '').includes('This payment link has ended')"))->toBeTrue('the refusal was never said');
    $page->script('window.notReloaded = true');
    usleep(3_000_000);

    expect((bool) $page->script('window.notReloaded === true'))->toBeTrue('the page reloaded')
        ->and((string) $page->script("document.querySelector('[data-test=\"pay\"]').getAttribute('aria-disabled')"))->toBe('true');
});

it('says the gateway is off in the form\'s place, without reloading', function () {
    ['page' => $page, 'number' => $number] = salesRequestReady(1280, 800);
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
    Cx::asSuperAdmin(static fn () => app(SwitchGatewayHandler::class)->handle(new SwitchGateway(Fx::storeId('sa'), false)));
    app()->scoped(ActorContext::class, $previous);
    app()->forgetScopedInstances();

    $page->navigate("/sa/en/orders/{$number}/pay", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "!!document.querySelector('[data-test=\"payment-failed\"]')"))->toBeTrue('the refusal was never said');
    $page->script('window.notReloaded = true');
    usleep(3_000_000);

    expect((bool) $page->script('window.notReloaded === true'))->toBeTrue('the page reloaded')
        ->and((string) $page->script("document.querySelector('[data-test=\"pay\"]').getAttribute('aria-disabled')"))->toBe('true');
});
