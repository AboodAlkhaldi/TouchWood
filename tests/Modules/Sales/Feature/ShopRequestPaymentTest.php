<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Sales\Application\Command\RequestOrderPayment\RequestOrderPayment;
use Modules\Sales\Application\Command\RequestOrderPayment\RequestOrderPaymentHandler;
use Modules\Sales\Application\Command\WithdrawOrderRequest\WithdrawOrderRequest;
use Modules\Sales\Application\Command\WithdrawOrderRequest\WithdrawOrderRequestHandler;
use Shared\Application\ActorContext;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Payments\Support\FakeMyFatoorah;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| A payment request's page over HTTP (payments.md §4.2, Q11–Q13; sales.md §9.9): the order's Note, My Orders' word, the page
| itself, and the form, Pay and its answer. Only the order's customer; an ended link says so; the amount is always the
| request's; paid goes back to the order, which says so once.
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
 * An individual's order placed through staff, a browser signed in as its customer, and the request staff sent unless told not to.
 *
 * @return array{fake: FakeMyFatoorah, browser: AdminBrowser, order: string, number: string, amount: string, minor: int, page: string}
 */
function shopRequestReady(string $mode = 'LIVE', bool $asked = true): array
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
    $fake = FakeMyFatoorah::install();

    try {
        Cx::asSuperAdmin(function () use ($mode): void {
            app(ChangeKeysHandler::class)->handle(new ChangeKeys(Fx::storeId('sa'), $mode, 'sk_key_ABC123', 'whsec_secret_1', '2030-12-31'));
            app(SwitchGatewayHandler::class)->handle(new SwitchGateway(Fx::storeId('sa'), true));
        });
        ['customer' => $customer, 'address' => $address] = Cx::ready();
        $placed = Cx::place(Cx::start($address)->id);

        if ($asked) {
            Cx::asSuperAdmin(static fn () => app(RequestOrderPaymentHandler::class)->handle(new RequestOrderPayment($placed->id)));
        }
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }

    $minor = (int) DB::table('sales.orders')->where('id', $placed->id)->value('order_total_minor');
    $email = (string) DB::table('access.customers')->where('id', $customer)->value('email');

    return [
        'fake' => $fake, 'browser' => shopRequestSignedIn($email), 'order' => $placed->id, 'number' => $placed->number,
        'amount' => number_format($minor / 100, 2, '.', ''), 'minor' => $minor, 'page' => "/sa/en/orders/{$placed->number}/pay",
    ];
}

function shopRequestSignedIn(string $email): AdminBrowser
{
    $browser = new AdminBrowser('10.9.7.'.random_int(20, 250));
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    return $browser;
}

/**
 * @return array<string, mixed>
 */
function shopRequestProps(AdminBrowser $browser, string $uri, string $component): array
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
function shopRequestPost(AdminBrowser $browser, string $uri, array $data = []): TestResponse
{
    return $browser->post($uri, $data, ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
}

/**
 * The form asked for, Pay pressed.
 *
 * @param  array{browser: AdminBrowser, page: string}  $ready
 * @return array<string, mixed>
 */
function shopRequestPay(array $ready): array
{
    $session = shopRequestPost($ready['browser'], "{$ready['page']}/session")->assertOk()->json('sessionId');

    return shopRequestPost($ready['browser'], "{$ready['page']}/charge", ['session' => $session])->assertOk()->json();
}

describe('the order and My Orders', function () {
    it('shows the amount and its time on the order, and Payment requested on the row', function () {
        $ready = shopRequestReady();
        $order = shopRequestProps($ready['browser'], "/sa/en/account/orders/{$ready['number']}", 'Sales/Storefront/Order');
        $rows = shopRequestProps($ready['browser'], '/sa/en/account/orders', 'Sales/Storefront/MyOrders')['orders'];

        expect($order['paymentRequest']['amount']['minor'])->toBe($ready['minor'])
            ->and($order['paymentRequest']['expiresAt'])->not->toBe('')
            ->and($order['paymentBeingConfirmed'])->toBeFalse()
            ->and($rows[0]['paymentRequested'])->toBeTrue();
    });

    it('shows neither with no request', function () {
        $ready = shopRequestReady(asked: false);

        expect(shopRequestProps($ready['browser'], "/sa/en/account/orders/{$ready['number']}", 'Sales/Storefront/Order')['paymentRequest'])->toBeNull()
            ->and(shopRequestProps($ready['browser'], '/sa/en/account/orders', 'Sales/Storefront/MyOrders')['orders'][0]['paymentRequested'])->toBeFalse();
    });

    it('puts Cancel out of reach while a payment is open, and says so', function () {
        $ready = shopRequestReady();
        shopRequestPay($ready);
        $order = shopRequestProps($ready['browser'], "/sa/en/account/orders/{$ready['number']}", 'Sales/Storefront/Order');

        expect($order['paymentBeingConfirmed'])->toBeTrue()
            ->and($order['mayCancel'])->toBeFalse();
    });
});

describe('the page', function () {
    it('shows the order and what is asked, and asks the gateway nothing', function () {
        $ready = shopRequestReady();
        $page = shopRequestProps($ready['browser'], $ready['page'], 'Sales/Storefront/PayRequest');

        expect($page['order']['number'])->toBe($ready['number'])
            ->and($page['order']['lines'])->not->toBeEmpty()
            ->and($page['order']['paymentRequest']['amount']['minor'])->toBe($ready['minor'])
            ->and($page['payment'])->toBeNull()
            ->and($ready['fake']->calls)->toBe([]);
    });

    it('says the link has ended, not a 404, for the order\'s own customer', function (bool $asked, bool $withdrawn) {
        $ready = shopRequestReady(asked: $asked);

        if ($withdrawn) {
            $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
            Cx::asSuperAdmin(static fn () => app(WithdrawOrderRequestHandler::class)->handle(new WithdrawOrderRequest($ready['order'])));
            app()->scoped(ActorContext::class, $previous);
            app()->forgetScopedInstances();
        }

        expect(shopRequestProps($ready['browser'], $ready['page'], 'Sales/Storefront/PayRequest')['order']['paymentRequest'])->toBeNull();
        shopRequestPost($ready['browser'], "{$ready['page']}/session")->assertStatus(409)->assertJsonPath('type', fn (string $type) => str_contains($type, 'payment_link_ended'));
    })->with(['never sent' => [false, false], 'withdrawn' => [true, true]]);

    it('answers a 404 to anyone else, and sends a guest to sign in', function () {
        $ready = shopRequestReady();
        Fx::customer('someone.else@example.test');
        $other = shopRequestSignedIn('someone.else@example.test');

        $other->get($ready['page'])->assertNotFound();
        shopRequestPost($other, "{$ready['page']}/session")->assertNotFound();
        (new AdminBrowser('10.9.6.1'))->get($ready['page'])->assertRedirect();
    });

    it('answers a 404 for the order in another store\'s address', function () {
        $ready = shopRequestReady();

        $ready['browser']->get("/eg/en/orders/{$ready['number']}/pay")->assertNotFound();
    });

    it('says a test order is one', function () {
        $ready = shopRequestReady('TEST');

        expect(shopRequestProps($ready['browser'], $ready['page'], 'Sales/Storefront/PayRequest')['order']['isTest'])->toBeTrue();
    });

    it('reads what is pinned here', function () {
        $ready = shopRequestReady();
        $ready['browser']->get($ready['page'])->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $ready['browser']->get($ready['page'])->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // A target, not a cap (owner, 2026-10-19): the order's own read, the request, whether a payment is open.
        expect($count)->toBeLessThanOrEqual(30);
    });
});

describe('paying', function () {
    it('opens a form, and charges the request\'s amount whatever is sent', function () {
        $ready = shopRequestReady();
        $form = shopRequestPost($ready['browser'], "{$ready['page']}/session")->assertOk()->json();
        $start = shopRequestPost($ready['browser'], "{$ready['page']}/charge", ['session' => $form['sessionId'], 'amount' => '1.00', 'amount_minor' => '100'])->assertOk()->json();

        expect($form['scriptUrl'])->toEndWith('/sessions/v1/session.js')
            ->and($start['tryId'])->not->toBe('')
            ->and($start['frameUrl'])->toBe(FakeMyFatoorah::FRAME_URL)
            ->and($start['holdEndsAt'] > $start['windowEndsAt'])->toBeTrue()
            ->and((int) DB::table('payments.attempts')->value('amount_minor'))->toBe($ready['minor']);
    });

    it('goes back to the order on paid, saying so once, with the money recorded once', function () {
        $ready = shopRequestReady();
        $start = shopRequestPay($ready);
        $ready['fake']->paid($ready['amount']);
        $follow = "{$ready['page']}/{$start['tryId']}/follow";

        $paid = shopRequestPost($ready['browser'], $follow)->assertOk()->assertJsonPath('orderNumber', $ready['number'])->assertJsonPath('state', 'OVER');
        $order = shopRequestProps($ready['browser'], "/sa/en/account/orders/{$ready['number']}", 'Sales/Storefront/Order');
        shopRequestPost($ready['browser'], $follow)->assertOk()->assertJsonPath('orderNumber', $ready['number']);

        expect(AdminBrowser::flashed($paid, 'status'))->toBe('Payment received.')
            ->and($order['paymentRequest'])->toBeNull()
            ->and($order['owed']['minor'])->toBe(0)
            ->and(DB::table('sales.payments')->where('order_id', $ready['order'])->count())->toBe(1)
            ->and(shopRequestProps($ready['browser'], $ready['page'], 'Sales/Storefront/PayRequest')['order']['paymentRequest'])->toBeNull();
    });

    it('comes back to a new form on a decline, with its reason, while the request lasts', function () {
        $ready = shopRequestReady();
        $start = shopRequestPay($ready);
        $used = (string) DB::table('payments.sessions')->orderByDesc('created_at')->value('id');
        $ready['fake']->declined('MF004');

        shopRequestPost($ready['browser'], "{$ready['page']}/{$start['tryId']}/follow")->assertOk()->assertJsonPath('state', 'OVER')->assertJsonPath('declineCode', 'MF004')->assertJsonPath('orderNumber', null);

        $page = shopRequestProps($ready['browser'], $ready['page'], 'Sales/Storefront/PayRequest');
        $again = shopRequestPost($ready['browser'], "{$ready['page']}/session")->assertOk()->json('sessionId');

        expect($page['payment'])->toBeNull()
            ->and($page['order']['paymentRequest'])->not->toBeNull()
            ->and($again)->not->toBe($used);
    });

    it('shows a payment still open with no form, from what is known, and refuses another', function () {
        $ready = shopRequestReady();
        $start = shopRequestPay($ready);
        $asked = count($ready['fake']->calls);

        $page = shopRequestProps($ready['browser'], $ready['page'], 'Sales/Storefront/PayRequest');

        expect($page['payment']['state'])->toBe('LIVE')
            ->and($page['payment']['tryId'])->toBe($start['tryId'])
            ->and($page['payment']['holdEndsAt'])->not->toBeNull()
            ->and(count($ready['fake']->calls))->toBe($asked);
        shopRequestPost($ready['browser'], "{$ready['page']}/session")->assertStatus(409);
    });

    it('refuses to follow what is not this order\'s, or not this customer\'s', function () {
        $ready = shopRequestReady();
        $start = shopRequestPay($ready);
        $unknown = shopRequestPost($ready['browser'], "{$ready['page']}/01jzzzzzzzzzzzzzzzzzzzzzzz/follow");

        $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
        Fx::customer('someone.else@example.test');
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
        $other = shopRequestPost(shopRequestSignedIn('someone.else@example.test'), "{$ready['page']}/{$start['tryId']}/follow");

        expect($unknown->status())->toBe(404, 'unknown: '.$unknown->headers->get('Location'))
            ->and($other->status())->toBe(404, 'other: '.$other->headers->get('Location'));
    });

    it('says a refusal the page can\'t change where the form would be, with no reload', function () {
        $ready = shopRequestReady();
        $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
        Cx::asSuperAdmin(static fn () => app(SwitchGatewayHandler::class)->handle(new SwitchGateway(Fx::storeId('sa'), false)));
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();

        $refused = shopRequestPost($ready['browser'], "{$ready['page']}/session")->assertStatus(409);

        // The page reloads only for these (PaymentBlock's REQUEST_RELOADS): this one is said in the form's place.
        expect((string) $refused->json('type'))->not->toContain('payment_link_ended')->not->toContain('payment_in_progress')->not->toContain('amount_changed')
            ->and((string) $refused->json('detail'))->not->toBe('');
    });

    it('answers too many tries with a 429 and charges nothing', function () {
        $ready = shopRequestReady();
        $session = shopRequestPost($ready['browser'], "{$ready['page']}/session")->assertOk()->json('sessionId');

        foreach (range(1, 10) as $ignored) {
            RateLimiter::hit("sales:pay:order:{$ready['order']}", 600);
        }

        shopRequestPost($ready['browser'], "{$ready['page']}/charge", ['session' => $session])->assertStatus(429);
        expect(DB::table('payments.attempts')->count())->toBe(0);
    });

    it('still answers its own follow once the store is switched off, and nothing else does', function () {
        $ready = shopRequestReady();
        $start = shopRequestPay($ready);
        $ready['fake']->paid($ready['amount']);
        DB::table('platform.stores')->where('id', Fx::storeId('sa'))->update(['is_base' => false, 'is_active' => false]);
        DB::table('platform.stores')->where('id', Fx::storeId('eg'))->update(['is_base' => true]);
        Cache::flush();

        $answer = shopRequestPost($ready['browser'], "{$ready['page']}/{$start['tryId']}/follow")->assertOk()->json();

        expect($answer['orderNumber'])->toBe($ready['number'])
            ->and($answer['mainStoreUrl'])->toContain('/eg/en');
        $ready['browser']->get($ready['page'])->assertNotFound();
    });
});
