<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Shipping\Application\Command\SwitchCarrier\SwitchCarrier;
use Modules\Shipping\Application\Command\SwitchCarrier\SwitchCarrierHandler;
use Shared\Application\ActorContext;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures as Bx;
use Tests\Modules\Payments\Support\FakeMyFatoorah;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Paying online at checkout over HTTP (payments.md §4.2; sales.md §9.8): Pay Online picked first, the form asked for by
| the page, Pay sending the quote and the session alone, the answer followed, a try still live on coming back, and a
| store switched off meanwhile still answering for that customer's own try and nothing else.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
    Sleep::fake();
    app()->instance(KeyTester::class, new class implements KeyTester
    {
        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void {}
    });
});

/**
 * A customer with a cart in 'sa' and a browser signed in as them; the gateway on, in this mode, unless none is asked for.
 *
 * @return array{0: FakeMyFatoorah, 1: AdminBrowser, 2: array{customer: string, address: string, variants: list<string>, product: string}}
 */
function shopPaymentReady(?string $mode = 'LIVE', string $type = 'individual'): array
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
    $fake = FakeMyFatoorah::install();

    try {
        if ($mode !== null) {
            Cx::asSuperAdmin(function () use ($mode): void {
                app(ChangeKeysHandler::class)->handle(new ChangeKeys(Fx::storeId('sa'), $mode, 'sk_key_ABC123', 'whsec_secret_1', '2030-12-31'));
                app(SwitchGatewayHandler::class)->handle(new SwitchGateway(Fx::storeId('sa'), true));
            });
        }

        $ready = Cx::ready($type);

        if ($type === 'company') {
            Bx::approved($ready['customer']);
        }
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }

    return [$fake, shopPaymentSignedIn($ready['customer']), $ready];
}

function shopPaymentSignedIn(string $customer): AdminBrowser
{
    $browser = new AdminBrowser('10.9.8.'.random_int(20, 250));
    $email = (string) DB::table('access.customers')->where('id', $customer)->value('email');
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    return $browser;
}

/**
 * @return array<string, mixed>
 */
function shopPaymentProps(AdminBrowser $browser, string $uri, string $component): array
{
    $props = [];

    $browser->get($uri)->assertOk()->assertInertia(function (AssertableInertia $page) use ($component, &$props) {
        $page->component($component);
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * @param  array<string, string>  $data
 * @return TestResponse<Response>
 */
function shopPaymentPost(AdminBrowser $browser, string $uri, array $data = []): TestResponse
{
    return $browser->post($uri, $data, ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
}

/**
 * Checkout opened, its form asked for, Pay pressed.
 *
 * @return array{tryId: string, total: string, start: array<string, mixed>}
 */
function shopPaymentPay(AdminBrowser $browser): array
{
    $checkout = shopPaymentProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');
    $session = shopPaymentPost($browser, '/sa/en/checkout/payment/session', ['quote' => $checkout['quoteId']])->assertOk()->json('sessionId');
    $start = shopPaymentPost($browser, '/sa/en/checkout/payment/pay', ['quote' => $checkout['quoteId'], 'session' => $session])->assertOk()->json();

    return ['tryId' => $start['tryId'], 'total' => number_format($checkout['total']['minor'] / 100, 2, '.', ''), 'start' => $start];
}

/** The base store can never be switched off by hand: another one stands as the main store here. */
function shopPaymentCloseTheStore(): void
{
    DB::table('platform.stores')->where('id', Fx::storeId('sa'))->update(['is_base' => false, 'is_active' => false]);
    DB::table('platform.stores')->where('id', Fx::storeId('eg'))->update(['is_base' => true]);
    Cache::flush();
}

describe('the way to pay', function () {
    it('picks Pay Online first for an individual, and asks the gateway nothing to draw the page', function () {
        [$fake, $browser] = shopPaymentReady();

        $checkout = shopPaymentProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');

        expect($checkout['ways'])->toBe(['ONLINE', 'STAFF_CONTACT'])
            ->and($checkout['way'])->toBe('ONLINE')
            ->and($checkout['testMode'])->toBeFalse()
            ->and($checkout['payment'])->toBeNull()
            ->and($fake->count('POST', '/v3/sessions'))->toBe(0);
    });

    it('keeps staff contact when it is chosen', function () {
        [, $browser] = shopPaymentReady();

        expect(shopPaymentProps($browser, '/sa/en/checkout?way=STAFF_CONTACT', 'Sales/Storefront/Checkout')['way'])->toBe('STAFF_CONTACT');
    });

    it('offers only staff contact with no gateway', function () {
        [, $browser] = shopPaymentReady(null);
        $none = shopPaymentProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');

        expect([$none['ways'], $none['way']])->toBe([['STAFF_CONTACT'], 'STAFF_CONTACT']);
    });

    it('offers a company no online payment, with the gateway on', function () {
        [, $browser] = shopPaymentReady('LIVE', 'company');
        $checkout = shopPaymentProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');

        expect($checkout['ways'])->not->toContain('ONLINE')
            ->and($checkout['way'])->not->toBe('ONLINE');
    });

    it('offers only staff contact while the fee is to be set, with the gateway on', function () {
        [, $browser] = shopPaymentReady();
        Cx::asSuperAdmin(static fn () => app(SwitchCarrierHandler::class)->handle(new SwitchCarrier(Fx::storeId('sa'), Cx::serve()['carrier'], false)));
        $checkout = shopPaymentProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');

        expect([$checkout['shippingPending'], $checkout['ways'], $checkout['way']])->toBe([true, ['STAFF_CONTACT'], 'STAFF_CONTACT']);
    });

    it('says when the store is in Test, with a quote or without one', function () {
        [, $browser, $ready] = shopPaymentReady('TEST');

        expect(shopPaymentProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout')['testMode'])->toBeTrue();

        DB::table('access.addresses')->where('customer_id', $ready['customer'])->delete();

        $none = shopPaymentProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');
        expect([$none['quoteId'], $none['testMode']])->toBe([null, true]);
    });

    it('marks an order placed in Test on the customer\'s own page', function () {
        [, $browser, $ready] = shopPaymentReady('TEST');
        $checkout = shopPaymentProps($browser, '/sa/en/checkout?way=STAFF_CONTACT', 'Sales/Storefront/Checkout');
        $browser->post('/sa/en/checkout/place', ['quote' => $checkout['quoteId']]);
        $number = (string) DB::table('sales.orders')->where('customer_id', $ready['customer'])->value('number');

        expect(shopPaymentProps($browser, "/sa/en/account/orders/{$number}", 'Sales/Storefront/Order')['isTest'])->toBeTrue();
    });
});

describe('paying', function () {
    it('opens the form for a quote, and asks once for the same quote', function () {
        [$fake, $browser] = shopPaymentReady();
        $quote = shopPaymentProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout')['quoteId'];

        $first = shopPaymentPost($browser, '/sa/en/checkout/payment/session', ['quote' => $quote])->assertOk()->json();
        $second = shopPaymentPost($browser, '/sa/en/checkout/payment/session', ['quote' => $quote])->assertOk()->json();

        expect($first['scriptUrl'])->toEndWith('/sessions/v1/session.js')
            ->and($second['sessionId'])->toBe($first['sessionId'])
            ->and($fake->count('POST', '/v3/sessions'))->toBe(1);
    });

    it('starts the try from the quote and the session, keeping the gateway\'s reasons from the page', function () {
        [, $browser] = shopPaymentReady();

        $paying = shopPaymentPay($browser);

        expect($paying['start']['frameUrl'])->toBe(FakeMyFatoorah::FRAME_URL)
            ->and(array_keys($paying['start']))->toBe(['tryId', 'frameUrl', 'windowEndsAt', 'holdEndsAt', 'serverNow'])
            // The window's 5 and the hold limit's 15, from Pay.
            ->and(strtotime($paying['start']['holdEndsAt']) - strtotime($paying['start']['windowEndsAt']))->toBe(10 * 60)
            ->and(DB::table('sales.checkout_tries')->where('id', $paying['tryId'])->value('state'))->toBe('LIVE');
    });

    it('keeps a refused charge\'s reason from the page, and says nothing was charged', function () {
        [$fake, $browser] = shopPaymentReady();
        $fake->charges[] = static fn () => Http::response(['IsSuccess' => false, 'Message' => 'The merchant secret reason'], 400);

        $paying = shopPaymentPay($browser);
        $view = shopPaymentPost($browser, "/sa/en/checkout/payment/{$paying['tryId']}/follow")->assertOk();

        expect($paying['start']['frameUrl'])->toBeNull()
            ->and(json_encode($paying['start']))->not->toContain('secret reason')
            ->and($view->getContent())->not->toContain('secret reason')
            ->and([$view->json('state'), $view->json('payment'), $view->json('nothingCharged')])->toBe(['RELEASED', 'FAILED', true]);
    });

    it('refuses a quote that is not this customer\'s', function () {
        [, $browser] = shopPaymentReady();

        shopPaymentPost($browser, '/sa/en/checkout/payment/session', ['quote' => (string) str()->ulid()])->assertNotFound()->assertHeader('Content-Type', 'application/problem+json');
    });

    it('goes to the order once paid, and the placed page says Paid', function () {
        [$fake, $browser] = shopPaymentReady();
        $paying = shopPaymentPay($browser);
        $fake->paid($paying['total']);

        $view = shopPaymentPost($browser, "/sa/en/checkout/payment/{$paying['tryId']}/follow")->assertOk()->json();
        $placed = shopPaymentProps($browser, "/sa/en/orders/{$view['orderNumber']}/placed", 'Sales/Storefront/Placed');

        expect([$view['state'], $view['payment'], $view['orderNumber'], $view['mainStoreUrl']])->toBe(['PAID', 'SUCCEEDED', 'SA-0001', null])
            ->and($placed['paid'])->toBeTrue();
    });

    it('releases a decline at once, naming the code for the page\'s words', function () {
        [$fake, $browser] = shopPaymentReady();
        $paying = shopPaymentPay($browser);
        $fake->declined('MF004');

        $view = shopPaymentPost($browser, "/sa/en/checkout/payment/{$paying['tryId']}/follow")->assertOk()->json();

        expect([$view['state'], $view['payment'], $view['declineCode'], $view['orderNumber']])->toBe(['RELEASED', 'FAILED', 'MF004', null])
            ->and(shopPaymentProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout')['payment'])->toBeNull();
    });

    it('answers too many tries with a 429 and changes nothing', function () {
        [, $browser] = shopPaymentReady();
        $paying = shopPaymentPay($browser);
        $quote = DB::table('sales.checkout_tries')->where('id', $paying['tryId'])->value('quote_id');
        $session = DB::table('sales.checkout_tries')->where('id', $paying['tryId'])->value('session_id');

        foreach (range(2, 10) as $press) {
            shopPaymentPost($browser, '/sa/en/checkout/payment/pay', ['quote' => $quote, 'session' => $session])->assertOk()->assertJsonPath('tryId', $paying['tryId']);
        }

        shopPaymentPost($browser, '/sa/en/checkout/payment/pay', ['quote' => $quote, 'session' => $session])->assertStatus(429);
        expect(DB::table('sales.checkout_tries')->count())->toBe(1);
    });

    it('says a changed cart is a changed quote, reserving nothing', function () {
        [, $browser, $ready] = shopPaymentReady();
        $checkout = shopPaymentProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');
        $session = shopPaymentPost($browser, '/sa/en/checkout/payment/session', ['quote' => $checkout['quoteId']])->json('sessionId');
        $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
        Fx::actAsCustomer($ready['customer']);
        Cx::add($ready['variants'][0], 1);
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();

        $refused = shopPaymentPost($browser, '/sa/en/checkout/payment/pay', ['quote' => $checkout['quoteId'], 'session' => $session])->assertStatus(409);

        expect($refused->json('type'))->toContain('quote_changed')
            ->and(DB::table('sales.checkout_tries')->count())->toBe(0);
    });

    it('never answers for another customer\'s try', function () {
        [, $browser] = shopPaymentReady();
        $paying = shopPaymentPay($browser);
        $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
        $other = Fx::customer();
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();

        shopPaymentPost(shopPaymentSignedIn($other), "/sa/en/checkout/payment/{$paying['tryId']}/follow")->assertNotFound();
    });
});

describe('coming back', function () {
    it('shows a charged try on checkout and on the cart, with no way to pay meanwhile', function () {
        [, $browser] = shopPaymentReady();
        $paying = shopPaymentPay($browser);

        $checkout = shopPaymentProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');
        $cart = shopPaymentProps($browser, '/sa/en/cart', 'Sales/Storefront/Cart');

        expect([$checkout['payment']['tryId'], $checkout['payment']['state']])->toBe([$paying['tryId'], 'LIVE'])
            ->and($checkout['payment']['holdEndsAt'])->not->toBeNull()
            ->and($cart['payment']['tryId'])->toBe($paying['tryId']);
    });

    it('goes straight to the new order once paid', function (string $page) {
        [$fake, $browser] = shopPaymentReady();
        $paying = shopPaymentPay($browser);
        $fake->paid($paying['total']);

        $browser->get($page)->assertRedirect('/sa/en/orders/SA-0001/placed');
    })->with(['checkout' => '/sa/en/checkout', 'the cart' => '/sa/en/cart']);

    it('leaves a guest\'s cart alone', function () {
        [$fake] = shopPaymentReady();

        expect(shopPaymentProps(new AdminBrowser('10.9.9.9'), '/sa/en/cart', 'Sales/Storefront/Cart')['payment'])->toBeNull()
            ->and($fake->calls)->toBe([]);
    });
});

describe('a store switched off while paying (§1.10)', function () {
    it('still answers for the customer\'s own try, says where the main store is, and only once', function () {
        [$fake, $browser] = shopPaymentReady();
        $paying = shopPaymentPay($browser);
        shopPaymentCloseTheStore();
        $fake->paid($paying['total']);

        $view = shopPaymentPost($browser, "/sa/en/checkout/payment/{$paying['tryId']}/follow")->assertOk()->json();
        $again = shopPaymentPost($browser, "/sa/en/checkout/payment/{$paying['tryId']}/follow")->assertOk()->json();

        expect([$view['orderNumber'], $view['firstSeen']])->toBe(['SA-0001', true])
            ->and($view['mainStoreUrl'])->toContain('/eg/en')
            ->and($again['firstSeen'])->toBeFalse();
    });

    it('answers nothing else: not another customer\'s try, not the other paying addresses, not the pages', function () {
        [, $browser] = shopPaymentReady();
        $paying = shopPaymentPay($browser);
        $quote = (string) DB::table('sales.checkout_tries')->where('id', $paying['tryId'])->value('quote_id');
        $session = (string) DB::table('sales.checkout_tries')->where('id', $paying['tryId'])->value('session_id');
        // While it is open, these answer: the 404s below are the store's, not the quote's.
        shopPaymentPost($browser, '/sa/en/checkout/payment/pay', ['quote' => $quote, 'session' => $session])->assertOk();
        shopPaymentPost($browser, '/sa/en/checkout/payment/session', ['quote' => $quote])->assertStatus(409);
        $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
        $other = Fx::customer();
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
        $stranger = shopPaymentSignedIn($other);
        shopPaymentCloseTheStore();

        shopPaymentPost($stranger, "/sa/en/checkout/payment/{$paying['tryId']}/follow")->assertNotFound();
        shopPaymentPost($browser, '/sa/en/checkout/payment/session', ['quote' => $quote])->assertNotFound();
        shopPaymentPost($browser, '/sa/en/checkout/payment/pay', ['quote' => $quote, 'session' => $session])->assertNotFound();
        $browser->get('/sa/en/checkout')->assertNotFound();
    });
});

describe('the bank frame\'s return', function () {
    it('only tells the page around it, with no session, in our frame alone', function () {
        $response = (new AdminBrowser('10.9.9.10'))->get('/sa/en/checkout/payment/return?paymentId=07077320290391998273');

        $response->assertOk()->assertSee('TW-payment-return', false)->assertDontSee('07077320290391998273');
        expect($response->headers->getCookies())->toBe([])
            ->and($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'self'");
    });
});
