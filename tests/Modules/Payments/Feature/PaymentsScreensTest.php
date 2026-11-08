<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Sales\Application\Command\OpenRequestPayment\OpenRequestPayment;
use Modules\Sales\Application\Command\OpenRequestPayment\OpenRequestPaymentHandler;
use Modules\Sales\Application\Command\PayOrderRequest\PayOrderRequest;
use Modules\Sales\Application\Command\PayOrderRequest\PayOrderRequestHandler;
use Modules\Sales\Application\Command\RequestOrderPayment\RequestOrderPayment;
use Modules\Sales\Application\Command\RequestOrderPayment\RequestOrderPaymentHandler;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Payments\Support\FakeMyFatoorah;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Staff's side of paying online over HTTP (payments.md §4.2, Q19–Q22; sales.md §9.9): the order's Request Payment,
| Withdraw Request and Online Payments block, the Payments list and an attempt, Ask the Gateway Now. Every helper is named
| after this file's subject: a Pest file's functions are global.
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
 * An individual's order placed through staff, its gateway on; asked for and paid into a frame when told.
 *
 * @return array{fake: FakeMyFatoorah, order: string, number: string, attempt: ?string}
 */
function paymentsScreenOrder(bool $asked = false, bool $paying = false): array
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
    $fake = FakeMyFatoorah::install();

    try {
        Cx::asSuperAdmin(function (): void {
            app(ChangeKeysHandler::class)->handle(new ChangeKeys(Fx::storeId('sa'), 'LIVE', 'sk_key_ABC123', 'whsec_secret_1', '2030-12-31'));
            app(SwitchGatewayHandler::class)->handle(new SwitchGateway(Fx::storeId('sa'), true));
        });
        ['address' => $address] = Cx::ready();
        $placed = Cx::place(Cx::start($address)->id);

        if ($asked || $paying) {
            Cx::asSuperAdmin(static fn () => app(RequestOrderPaymentHandler::class)->handle(new RequestOrderPayment($placed->id)));
        }

        if ($paying) {
            $session = app(OpenRequestPaymentHandler::class)->handle(new OpenRequestPayment(Fx::storeId('sa'), $placed->number, 'en'))->sessionId;
            app(PayOrderRequestHandler::class)->handle(new PayOrderRequest(Fx::storeId('sa'), $placed->number, $session, 'en', 'https://example.test/back'));
        }
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }

    return ['fake' => $fake, 'order' => $placed->id, 'number' => $placed->number, 'attempt' => DB::table('payments.attempts')->value('id')];
}

/** @param  list<string>|null  $jobs  none: a Super Admin */
function paymentsScreenStaff(?array $jobs = null): AdminBrowser
{
    $staff = $jobs === null ? Fx::staff(superAdmin: true) : Fx::staffWith($jobs, ['sa']);
    $browser = new AdminBrowser('10.9.5.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staff)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/** @return array<string, mixed> */
function paymentsScreenProps(AdminBrowser $browser, string $uri, string $component): array
{
    $props = [];
    $browser->get($uri)->assertOk()->assertInertia(function (AssertableInertia $page) use ($component, &$props) {
        $page->component($component);
        $props = $page->toArray()['props'];
    });

    return $props;
}

describe('the order', function () {
    it('offers Request Payment for what is owed, then shows the request with Withdraw Request', function () {
        $ready = paymentsScreenOrder();
        $staff = paymentsScreenStaff();
        $before = paymentsScreenProps($staff, "/admin/orders/{$ready['order']}", 'Sales/Admin/Orders/Show');

        $staff->post("/admin/orders/{$ready['order']}/request-payment")->assertRedirect();
        $asked = paymentsScreenProps($staff, "/admin/orders/{$ready['order']}", 'Sales/Admin/Orders/Show');

        $staff->post("/admin/orders/{$ready['order']}/withdraw-request")->assertRedirect();
        $withdrawn = paymentsScreenProps($staff, "/admin/orders/{$ready['order']}", 'Sales/Admin/Orders/Show');

        expect($before['mayRequestPayment'])->toBeTrue()
            ->and($before['paymentRequest'])->toBeNull()
            ->and($asked['paymentRequest']['amount']['minor'])->toBe($before['owed']['minor'])
            ->and($asked['mayRequestPayment'])->toBeFalse()
            ->and($withdrawn['paymentRequest'])->toBeNull()
            ->and($withdrawn['mayRequestPayment'])->toBeTrue();
    });

    it('shows a payment in progress and its attempt, and records nothing by hand meanwhile', function () {
        $ready = paymentsScreenOrder(paying: true);
        $staff = paymentsScreenStaff();
        $order = paymentsScreenProps($staff, "/admin/orders/{$ready['order']}", 'Sales/Admin/Orders/Show');

        expect($order['paymentInProgress'])->toBeTrue()
            ->and($order['attempts'])->toHaveCount(1)
            ->and($order['attempts'][0]['id'])->toBe($ready['attempt']);

        $staff->post("/admin/orders/{$ready['order']}/payment", ['way' => 'CASH', 'happened_on' => '2026-11-02', 'shown' => $order['owedDecimal']])->assertRedirect();
        expect(DB::table('sales.payments')->where('order_id', $ready['order'])->count())->toBe(0);
    });

    it('hides the Online Payments block from staff without payments.payment.view', function () {
        $ready = paymentsScreenOrder(paying: true);
        $staff = paymentsScreenStaff(['sales.order.view']);

        expect(paymentsScreenProps($staff, "/admin/orders/{$ready['order']}", 'Sales/Admin/Orders/Show')['attempts'])->toBeNull();
    });

    it('carries the overdue mark on the list\'s rows, off for an order with no transfer receipt', function () {
        $ready = paymentsScreenOrder();
        $rows = paymentsScreenProps(paymentsScreenStaff(), "/admin/orders?store=sa&q={$ready['number']}", 'Sales/Admin/Orders/Index')['orders'];

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['isOverdue'])->toBeFalse();
    });
});

describe('the Payments list', function () {
    it('lists an attempt with its order, and filters by state and mode', function () {
        $ready = paymentsScreenOrder(paying: true);
        $staff = paymentsScreenStaff();
        $page = paymentsScreenProps($staff, '/admin/payments', 'Payments/Admin/Payments');

        expect($page['rows'])->toHaveCount(1)
            ->and($page['rows'][0]['id'])->toBe($ready['attempt'])
            ->and($page['rows'][0]['orderNumber'])->toBe($ready['number'])
            ->and($page['rows'][0]['amount']['minor'])->toBeGreaterThan(0)
            ->and($page['needingAttention'])->toBe([])
            ->and(paymentsScreenProps($staff, '/admin/payments?mode=TEST', 'Payments/Admin/Payments')['rows'])->toBe([])
            ->and(paymentsScreenProps($staff, '/admin/payments?state=SUCCEEDED', 'Payments/Admin/Payments')['rows'])->toBe([])
            ->and(paymentsScreenProps($staff, '/admin/payments?state=nonsense&store=sa', 'Payments/Admin/Payments')['state'])->toBeNull();
    });

    it('puts what needs attention first, saying why', function () {
        $ready = paymentsScreenOrder(paying: true);
        DB::table('payments.attempts')->where('id', $ready['attempt'])->update(['needs_attention' => true, 'state' => 'UNKNOWN']);
        $page = paymentsScreenProps(paymentsScreenStaff(), '/admin/payments', 'Payments/Admin/Payments');

        expect($page['needingAttentionCount'])->toBe(1)
            ->and($page['needingAttention'][0]['reasons'])->toBe(['NO_ANSWER'])
            ->and($page['rows'])->toBe([]);
    });

    it('refuses staff without payments.payment.view, on the list, an attempt and Ask', function () {
        $ready = paymentsScreenOrder(paying: true);
        $staff = paymentsScreenStaff(['sales.order.view']);

        $staff->get('/admin/payments')->assertForbidden();
        $staff->get("/admin/payments/{$ready['attempt']}")->assertForbidden();
        $asked = count($ready['fake']->calls);
        $staff->post("/admin/payments/{$ready['attempt']}/check");

        expect(count($ready['fake']->calls))->toBe($asked);
    });
});

describe('an attempt', function () {
    it('shows the attempt, its calls with what was sent, and the customer for those who may read them', function () {
        $ready = paymentsScreenOrder(paying: true);
        $page = paymentsScreenProps(paymentsScreenStaff(), "/admin/payments/{$ready['attempt']}", 'Payments/Admin/Attempt');

        expect($page['attempt']['orderNumber'])->toBe($ready['number'])
            ->and($page['canAsk'])->toBeTrue()
            ->and($page['refundDue'])->toBeFalse()
            ->and($page['mayReadContact'])->toBeTrue()
            ->and($page['contactEmail'])->toContain('@')
            ->and($page['calls'])->not->toBeEmpty()
            ->and(implode('', array_map(static fn (array $call): string => ($call['request'] ?? '').($call['response'] ?? ''), $page['calls'])))->not->toContain('sk_key_ABC123');
    });

    it('keeps the customer\'s details from staff who may not read customers', function () {
        $ready = paymentsScreenOrder(paying: true);
        $page = paymentsScreenProps(paymentsScreenStaff(['payments.payment.view']), "/admin/payments/{$ready['attempt']}", 'Payments/Admin/Attempt');

        expect($page['mayReadContact'])->toBeFalse()
            ->and([$page['contactName'], $page['contactEmail'], $page['contactPhone']])->toBe([null, null, null]);
    });

    it('says a refund is due where money was taken that no order keeps', function () {
        $ready = paymentsScreenOrder(paying: true);
        DB::table('payments.attempts')->where('id', $ready['attempt'])->update(['needs_attention' => true, 'paid_twice' => true]);

        expect(paymentsScreenProps(paymentsScreenStaff(), "/admin/payments/{$ready['attempt']}", 'Payments/Admin/Attempt')['refundDue'])->toBeTrue();
    });

    it('asks the gateway once and says the answer in a sentence', function () {
        $ready = paymentsScreenOrder(paying: true);
        $staff = paymentsScreenStaff();
        $asked = count($ready['fake']->calls);

        $checked = $staff->post("/admin/payments/{$ready['attempt']}/check")->assertRedirect();
        expect(count($ready['fake']->calls))->toBeGreaterThan($asked)
            ->and(AdminBrowser::flashed($checked, 'status'))->toStartWith('MyFatoorah says');
    });

    it('answers a 404 for an attempt that does not exist', function () {
        paymentsScreenStaff()->get('/admin/payments/01jzzzzzzzzzzzzzzzzzzzzzzz')->assertNotFound();
    });
});
