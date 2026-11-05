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
use Modules\Sales\Application\Command\OpenRequestPayment\OpenRequestPayment;
use Modules\Sales\Application\Command\OpenRequestPayment\OpenRequestPaymentHandler;
use Modules\Sales\Application\Command\PayOrderRequest\PayOrderRequest;
use Modules\Sales\Application\Command\PayOrderRequest\PayOrderRequestHandler;
use Modules\Sales\Application\Command\RequestOrderPayment\RequestOrderPayment;
use Modules\Sales\Application\Command\RequestOrderPayment\RequestOrderPaymentHandler;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts;
use Tests\Modules\Payments\Support\FakeMyFatoorah;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Staff's side of paying online in a real browser (payments.md §4.2, Q19–Q22), at a phone's width and a desktop's: the
| order's Request Payment and Withdraw Request, its Online Payments block, the Payments list, an attempt and Ask the
| Gateway Now.
|
| Deliberately no RefreshDatabase, as the other browser tests: what is made here goes again after each test.
*/

const PAYMENTS_ADMIN_FITS = 'document.documentElement.scrollWidth <= window.innerWidth';

afterEach(function () {
    DB::statement('TRUNCATE payments.transactions, payments.attempts, payments.requests, payments.sessions, payments.calls, payments.gateway_keys, payments.gateways CASCADE');
    Fx::asSystem(fn () => app(StoreDirectory::class)->invalidate());
    app()->forgetScopedInstances();
    Cx::unserve();
});

beforeEach(function () {
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
 * An individual's order placed through staff, the gateway on; paid into a frame when told. A Super Admin signed in.
 *
 * @return array{page: mixed, order: string, number: string}
 */
function paymentsAdminReady(bool $paying, int $width = 375, int $height = 812): array
{
    FakeMyFatoorah::install();
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        Cx::asSuperAdmin(function (): void {
            app(ChangeKeysHandler::class)->handle(new ChangeKeys(Fx::storeId('sa'), 'LIVE', 'sk_key_ABC123', 'whsec_secret_1', '2030-12-31'));
            app(SwitchGatewayHandler::class)->handle(new SwitchGateway(Fx::storeId('sa'), true));
        });
        ['address' => $address] = Cx::ready();
        $placed = Cx::place(Cx::start($address)->id);

        if ($paying) {
            Cx::asSuperAdmin(static fn () => app(RequestOrderPaymentHandler::class)->handle(new RequestOrderPayment($placed->id)));
            $session = app(OpenRequestPaymentHandler::class)->handle(new OpenRequestPayment(Fx::storeId('sa'), $placed->number, 'en'))->sessionId;
            app(PayOrderRequestHandler::class)->handle(new PayOrderRequest(Fx::storeId('sa'), $placed->number, $session, 'en', 'https://example.test/back'));
        }
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }

    $page = visit('/admin/sign-in', ['timeout' => 20_000])
        ->type('#email', (string) DB::table('access.staff_users')->where('id', Fx::staff(superAdmin: true))->value('email'))
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');
    expect(signedInToPanel($page))->toBeTrue();
    $page->resize($width, $height);

    return ['page' => $page, 'order' => $placed->id, 'number' => $placed->number];
}

it('asks for payment from the order and withdraws it, on a phone', function () {
    ['page' => $page, 'order' => $order] = paymentsAdminReady(false);

    $page->navigate("/admin/orders/{$order}", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "(document.querySelector('[data-test=\"payment-owed\"]')?.textContent ?? '').includes('The customer owes')"))->toBeTrue()
        ->and(browserSoon($page, PAYMENTS_ADMIN_FITS))->toBeTrue('the order scrolls sideways');

    $page->click('[data-test="request-payment"]');
    expect(browserSoon($page, "(document.querySelector('[data-test=\"payment-request\"]')?.textContent ?? '').includes('open until')"))->toBeTrue('the request never showed');

    $page->click('[data-test="withdraw-request"]');
    expect(browserSoon($page, "!!document.querySelector('[data-test=\"payment-owed\"]')"))->toBeTrue('the request never went');
});

it('shows a payment in progress on the order, and its attempt from the Payments list, on a desktop', function () {
    ['page' => $page, 'order' => $order, 'number' => $number] = paymentsAdminReady(true, 1280, 800);

    $page->navigate("/admin/orders/{$order}", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "!!document.querySelector('[data-test=\"payment-confirming\"]')"))->toBeTrue()
        ->and(browserSoon($page, "!!document.querySelector('[data-test=\"online-payment\"]')"))->toBeTrue('no Online Payments block')
        ->and((string) $page->script("document.querySelector('[data-test=\"record-payment\"]').getAttribute('aria-disabled')"))->toBe('true');

    $page->navigate('/admin/payments', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "(document.querySelector('[data-test=\"payments\"]')?.textContent ?? '').includes('{$number}')"))->toBeTrue('the list never showed the attempt');

    $page->click('[data-test="payments"] table [data-test="attempt-link"]');
    expect(browserSoon($page, "location.pathname.startsWith('/admin/payments/') && !!document.querySelector('[data-test=\"calls\"]')"))->toBeTrue('the attempt never opened');

    $page->click('[data-test="call"] button');
    expect(browserSoon($page, "!!document.querySelector('[data-test=\"call-body\"]')"))->toBeTrue('a call never opened');

    $page->click('[data-test="ask-gateway"]');
    expect(browserSoon($page, "document.body.textContent.includes('MyFatoorah says')"))->toBeTrue('the answer was never said');
});

it('fits the Payments list and an attempt on a phone', function () {
    ['page' => $page] = paymentsAdminReady(true);

    $page->navigate('/admin/payments', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "!!document.querySelector('[data-test=\"payment-row\"]')"))->toBeTrue()
        ->and(browserSoon($page, PAYMENTS_ADMIN_FITS))->toBeTrue('the list scrolls sideways');

    $page->click('[data-test="payments"] ul [data-test="attempt-link"]');
    expect(browserSoon($page, "!!document.querySelector('[data-test=\"calls\"]')"))->toBeTrue()
        ->and(browserSoon($page, PAYMENTS_ADMIN_FITS))->toBeTrue('the attempt scrolls sideways');
});
