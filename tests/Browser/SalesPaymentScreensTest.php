<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\BankAccountDto;
use Modules\Payments\Public\Dto\OnlinePaymentDto;
use Modules\Payments\Public\Dto\PaymentDto;
use Modules\Payments\Public\Dto\PaymentSessionDto;
use Modules\Payments\Public\Dto\PaymentStartDto;
use Modules\Payments\Public\Dto\PaymentStateDto;
use Modules\Payments\Public\Dto\PaymentTimersDto;
use Modules\Payments\Public\Dto\PayRequestDto;
use Modules\Payments\Public\Dto\SessionRequestDto;
use Modules\Payments\Public\Enums\PaymentAudience;
use Modules\Platform\Application\Query\StoreDirectory;
use Shared\Application\ActorContext;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts;
use Tests\Modules\Payments\Support\FakeMyFatoorah;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| Paying online in a real browser (payments.md §4.2), at a phone's width and a desktop's, against a fake form and a fake
| bank page: MyFatoorah's own script and pages need a key and a staging site. Pay locks the page; the frame's message makes
| the page ask; a forged one is ignored; a decline comes back to the form; coming back shows the note; a store closed
| meanwhile gets its one page.
|
| Deliberately no RefreshDatabase, as the other browser tests: what is made here goes again after each test.
*/

afterEach(function () {
    DB::statement('TRUNCATE payments.transactions, payments.attempts, payments.sessions, payments.gateway_keys, payments.gateways CASCADE');
    DB::table('sales.checkout_tries')->delete();
    // By code: a store switched off has no id to ask Platform for.
    DB::table('platform.stores')->where('code', 'eg')->update(['is_base' => false]);
    DB::table('platform.stores')->where('code', 'sa')->update(['is_base' => true, 'is_active' => true]);
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

    config(['testing.payment_script_down' => false]);
    Route::get('__fake-mf/session.js', static fn () => config('testing.payment_script_down') === true ? response('', 503) : response(<<<'JS'
        window.myfatoorah = {
            init(config) {
                window.fakeForm = config;
                document.getElementById(config.containerId).innerHTML = '<div data-test="fake-form">The card form</div>';
            },
            validateCardInputs() { return Promise.resolve(); },
            submitCardPayment() { window.fakeForm.callback({ isSuccess: true, paymentType: 'CARD', sessionId: window.fakeForm.sessionId, paymentCompleted: false }); },
        };
        JS, 200, ['Content-Type' => 'text/javascript']));
    Route::get('__fake-mf/bank', static fn () => response(<<<'HTML'
        <!doctype html><title>Bank</title><p>Enter your code</p>
        <script>window.addEventListener('message', (event) => { if (event.data === 'finish') { window.parent.postMessage(JSON.stringify({ sender: 'MF-3DSecure', url: 'x' }), '*'); } }); window.bankReady = true;</script>
        HTML));

    // The real Payments behind it; only the two addresses the page opens are ours.
    app()->instance(PaymentsApi::class, new class(app(PaymentsApi::class)) implements PaymentsApi
    {
        public function __construct(private PaymentsApi $real) {}

        public function availability(StoreId $store, PaymentAudience $audience): OnlinePaymentDto
        {
            return $this->real->availability($store, $audience);
        }

        public function openSession(SessionRequestDto $request): PaymentSessionDto
        {
            $session = $this->real->openSession($request);

            return new PaymentSessionDto($session->sessionId, '/__fake-mf/session.js', $session->mode, $session->expiresAt, $session->applePay);
        }

        public function prepare(PayRequestDto $request): string
        {
            return $this->real->prepare($request);
        }

        public function charge(string $attemptId): PaymentStartDto
        {
            $start = $this->real->charge($attemptId);

            return new PaymentStartDto($start->attemptId, $start->frameUrl === null ? null : url('/__fake-mf/bank'), $start->windowEndsAt, $start->state, $start->nothingCharged, $start->refusedReason);
        }

        public function abandon(string $attemptId): bool
        {
            return $this->real->abandon($attemptId);
        }

        public function refresh(string $ownerId): PaymentStateDto
        {
            return $this->real->refresh($ownerId);
        }

        public function lastKnown(string $ownerId): PaymentStateDto
        {
            return $this->real->lastKnown($ownerId);
        }

        public function bankAccount(StoreId $store): ?BankAccountDto
        {
            return $this->real->bankAccount($store);
        }

        public function inTestMode(StoreId $store): bool
        {
            return $this->real->inTestMode($store);
        }

        public function timers(StoreId $store): PaymentTimersDto
        {
            return $this->real->timers($store);
        }

        public function needsAttention(string $attemptId): void
        {
            $this->real->needsAttention($attemptId);
        }

        public function transferCheckHours(StoreId $store): int
        {
            return $this->real->transferCheckHours($store);
        }

        public function payment(string $paymentId): ?PaymentDto
        {
            return $this->real->payment($paymentId);
        }
    });
});

/**
 * @return array{0: FakeMyFatoorah, 1: mixed} the gateway's fake, and a page signed in as a customer with a cart
 */
function salesPaymentReady(int $width = 375, int $height = 812): array
{
    $fake = FakeMyFatoorah::install();
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        Cx::asSuperAdmin(function (): void {
            app(ChangeKeysHandler::class)->handle(new ChangeKeys(Fx::storeId('sa'), 'LIVE', 'sk_key_ABC123', 'whsec_secret_1', '2030-12-31'));
            app(SwitchGatewayHandler::class)->handle(new SwitchGateway(Fx::storeId('sa'), true));
        });
        ['customer' => $customer] = Cx::ready();
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }

    $email = (string) DB::table('access.customers')->where('id', $customer)->value('email');
    // The framework loaded in this process first: a cold first page on a busy machine outlasts a browser's patience.
    get('/sa/en/sign-in');
    $page = visit('/sa/en/sign-in', ['timeout' => 20_000]);
    salesPaymentSoon($page, "!!document.querySelector('#email')");
    $page->type('#email', $email)->type('#password', Fx::CUSTOMER_PASSWORD)->click('button[type="submit"]');
    expect(browserUntil($page, "location.pathname === '/sa/en'"))->toBeTrue();
    $page->resize($width, $height);

    return [$fake, $page];
}

/** browserUntil for what a slower answer decides: a poll making an order, on a busy machine. */
function salesPaymentSoon(mixed $page, string $expression): bool
{
    foreach (range(1, 4) as $round) {
        if (browserUntil($page, $expression)) {
            return true;
        }
    }

    return false;
}

/** Checkout open, the form drawn, Pay pressed, the bank's page in its frame. */
function salesPaymentPay(mixed $page): void
{
    $page->navigate('/sa/en/checkout', BROWSER_PAGE_LOAD);
    expect(browserUntil($page, "!!document.querySelector('[data-test=\"fake-form\"]')"))->toBeTrue('the form never drew');
    $page->click('[data-test="pay"]');
    expect(browserUntil($page, "!!document.querySelector('[data-test=\"payment-frame\"]')"))->toBeTrue('the frame never opened');
}

function salesPaymentFinish(mixed $page): void
{
    // The frame's element comes before the page inside it: a message sent sooner reaches nobody.
    expect(salesPaymentSoon($page, "document.querySelector('[data-test=\"payment-frame\"]')?.contentWindow?.bankReady === true"))->toBeTrue('the bank page never loaded');
    $page->script("document.querySelector('[data-test=\"payment-frame\"]').contentWindow.postMessage('finish', '*')");
}

function salesPaymentWide(mixed $page): string
{
    return (string) $page->script('document.documentElement.scrollWidth > document.documentElement.clientWidth ? String(document.documentElement.scrollWidth) : ""');
}

function salesPaymentTotal(): string
{
    return number_format((int) DB::table('payments.attempts')->value('amount_minor') / 100, 2, '.', '');
}

it('pays on a phone: the page locked, a forged message ignored, closing the frame asked about, then Paid', function () {
    [$fake, $page] = salesPaymentReady();
    $page->navigate('/sa/en/checkout', BROWSER_PAGE_LOAD);
    expect(browserUntil($page, "!!document.querySelector('[data-test=\"fake-form\"]')"))->toBeTrue()
        ->and(salesPaymentWide($page))->toBe('', 'checkout scrolls sideways')
        ->and((string) $page->script("document.querySelector('[data-test=\"pay\"]').textContent"))->toContain('Pay ·');

    salesPaymentPay($page);
    expect(salesPaymentWide($page))->toBe('', 'the frame scrolls the page sideways')
        ->and((string) $page->script("document.querySelector('[data-test=\"payment-frame-time\"] span').textContent"))->toMatch('/^0\d:\d\d$/')
        ->and((bool) $page->script("document.querySelector('[data-test=\"payment-frame-time\"]').getBoundingClientRect().height > 0"))->toBeTrue()
        ->and((bool) $page->script("!!document.querySelector('[data-test=\"checkout-address\"]').closest('[inert]')"))->toBeTrue();

    // Not from the frame: nothing changes.
    $page->script("window.postMessage(JSON.stringify({ sender: 'MF-3DSecure' }), '*')");
    usleep(300_000);
    expect((bool) $page->script("!!document.querySelector('[data-test=\"payment-frame\"]')"))->toBeTrue();

    $page->click('[data-test="payment-frame-dialog"] [data-slot="dialog-close"]');
    expect(browserUntil($page, "!!document.querySelector('[data-test=\"payment-leave\"]')"))->toBeTrue();
    $page->click('[data-test="payment-stay"]');

    $fake->paid(salesPaymentTotal());
    salesPaymentFinish($page);
    expect(browserUntil($page, "location.pathname.endsWith('/placed')"))->toBeTrue()
        ->and(browserUntil($page, "!!document.querySelector('[data-test=\"placed-paid\"]')"))->toBeTrue();
});

it('comes back from a decline to the form, with the reason in plain words, and may pay again', function () {
    [$fake, $page] = salesPaymentReady(1280, 800);
    salesPaymentPay($page);

    $fake->declined('MF004');
    salesPaymentFinish($page);

    expect(browserUntil($page, "(document.querySelector('[data-test=\"payment-notice\"]')?.textContent ?? '').includes('No money was taken')"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('[data-test=\"payment-notice\"]').textContent"))->toContain('enough funds')
        ->and(browserUntil($page, "!!document.querySelector('[data-test=\"fake-form\"]') && document.querySelector('[data-test=\"pay\"]').getAttribute('aria-disabled') === null"))->toBeTrue()
        ->and((bool) $page->script("!!document.querySelector('[data-test=\"checkout-address\"]').closest('[inert]')"))->toBeFalse();

    // A fresh try: the old one gone, the frame open again. A real gateway names a new payment.
    $fake->charges[] = static fn () => Http::response(['IsSuccess' => true, 'Message' => '', 'ValidationErrors' => null, 'Data' => [
        'InvoiceId' => '7320291', 'PaymentId' => '07077320291391998274', 'PaymentURL' => FakeMyFatoorah::FRAME_URL, 'PaymentCompleted' => false,
        'Card' => ['Number' => '446404xxxxxx0007', 'Brand' => 'Visa'],
    ]], 201);
    $page->click('[data-test="pay"]');
    expect(browserUntil($page, "!!document.querySelector('[data-test=\"payment-frame\"]')"))->toBeTrue()
        ->and(DB::table('sales.checkout_tries')->count())->toBe(2);
});

it('says a last payment can still go through on coming back, on the cart and on checkout, with no form', function () {
    [, $page] = salesPaymentReady();
    salesPaymentPay($page);

    $page->navigate('/sa/en/cart', BROWSER_PAGE_LOAD);
    expect(browserUntil($page, "!!document.querySelector('[data-test=\"payment-in-the-way\"]')"))->toBeTrue();

    $page->navigate('/sa/en/checkout', BROWSER_PAGE_LOAD);
    expect(browserUntil($page, "!!document.querySelector('[data-test=\"payment-in-the-way\"]')"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('[data-test=\"payment-in-the-way\"]').textContent"))->toContain('can still go through')
        ->and((bool) $page->script("!!document.querySelector('[data-test=\"payment-block\"]')"))->toBeFalse()
        ->and(salesPaymentWide($page))->toBe('', 'the note scrolls the page sideways');

    // Another window that is paying answers: this one is the second window.
    $page->script("new BroadcastChannel('tw-payment').postMessage('here')");
    expect(browserUntil($page, "document.querySelector('[data-test=\"payment-in-the-way\"]').textContent.includes('in another window')"))->toBeTrue();
});

it('comes back by itself when a try ends behind the note, with no reload (Q9)', function () {
    [$fake, $page] = salesPaymentReady();
    salesPaymentPay($page);
    $page->navigate('/sa/en/checkout', BROWSER_PAGE_LOAD);
    expect(browserUntil($page, "!!document.querySelector('[data-test=\"payment-in-the-way\"]')"))->toBeTrue();
    // The note's own ask, past the 10 seconds the server keeps an answer.
    $fake->declined('MF004');
    $page->script('window.notReloaded = true');

    sleep(16);

    expect(salesPaymentSoon($page, "!document.querySelector('[data-test=\"payment-in-the-way\"]') && !!document.querySelector('[data-test=\"fake-form\"]')"))->toBeTrue()
        ->and((bool) $page->script('window.notReloaded === true'))->toBeTrue();
});

it('says a window that is paying is there, when another asks', function () {
    [, $page] = salesPaymentReady();
    salesPaymentPay($page);

    $answered = $page->script(<<<'JS'
        () => new Promise((resolve) => {
            const channel = new BroadcastChannel('tw-payment');
            channel.onmessage = (event) => resolve(event.data);
            channel.postMessage('ping');
            setTimeout(() => resolve('nobody'), 2000);
        })
        JS);

    expect($answered)->toBe('here');
});

it('says when the form can\'t load, and Try Again brings it', function () {
    [, $page] = salesPaymentReady();
    config(['testing.payment_script_down' => true]);
    $page->navigate('/sa/en/checkout', BROWSER_PAGE_LOAD);

    expect(browserUntil($page, "!!document.querySelector('[data-test=\"payment-failed\"]')"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('[data-test=\"pay\"]').getAttribute('aria-disabled')"))->toBe('true');

    config(['testing.payment_script_down' => false]);
    $page->click('[data-test="payment-try-again"]');

    expect(browserUntil($page, "!!document.querySelector('[data-test=\"fake-form\"]')"))->toBeTrue();
});

it('keeps checkout working when the payment code can\'t load, saying so in the card', function () {
    [, $page] = salesPaymentReady();
    // The real thing: a version published while the page was open leaves the old file gone.
    $file = glob(public_path('build/assets/PaymentBlock-*.js'))[0] ?? null;
    expect($file)->not->toBeNull();
    rename((string) $file, $file.'.away');

    try {
        $page->navigate('/sa/en/checkout', BROWSER_PAGE_LOAD);

        expect(salesPaymentSoon($page, "!!document.querySelector('[data-test=\"payment-unavailable\"]')"))->toBeTrue()
            ->and((bool) $page->script("!!document.querySelector('[data-test=\"checkout-summary\"]') && !!document.querySelector('[data-test=\"checkout-address\"]')"))->toBeTrue()
            ->and((string) $page->script("document.querySelector('[data-test=\"pay\"]').getAttribute('aria-disabled')"))->toBe('true');
    } finally {
        rename($file.'.away', (string) $file);
    }

    // Staff contact still works from the same page.
    $page->click('[data-test="way-STAFF_CONTACT"]');
    expect(browserUntil($page, "!!document.querySelector('[data-test=\"place-order\"]')"))->toBeTrue();
});

it('shows a store closed behind the cart\'s note its one page too', function () {
    [$fake, $page] = salesPaymentReady();
    salesPaymentPay($page);
    $page->navigate('/sa/en/cart', BROWSER_PAGE_LOAD);
    expect(browserUntil($page, "!!document.querySelector('[data-test=\"payment-in-the-way\"]')"))->toBeTrue();

    $fake->paid(salesPaymentTotal());
    DB::table('platform.stores')->where('code', 'sa')->update(['is_base' => false, 'is_active' => false]);
    DB::table('platform.stores')->where('code', 'eg')->update(['is_base' => true]);
    Fx::asSystem(fn () => app(StoreDirectory::class)->invalidate());
    app()->forgetScopedInstances();

    sleep(16);

    expect(salesPaymentSoon($page, "!!document.querySelector('[data-test=\"payment-store-closed\"]')"))->toBeTrue();
});

it('shows a store closed while paying its one page, with the order and a way to the main store', function () {
    [$fake, $page] = salesPaymentReady();
    salesPaymentPay($page);

    $fake->paid(salesPaymentTotal());
    DB::table('platform.stores')->where('code', 'sa')->update(['is_base' => false, 'is_active' => false]);
    DB::table('platform.stores')->where('code', 'eg')->update(['is_base' => true]);
    Fx::asSystem(fn () => app(StoreDirectory::class)->invalidate());
    app()->forgetScopedInstances();
    salesPaymentFinish($page);

    expect(browserUntil($page, "!!document.querySelector('[data-test=\"payment-store-closed\"]')"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('[data-test=\"payment-store-closed\"]').textContent"))->toContain('is recorded')
        ->and((string) $page->script("document.querySelector('[data-test=\"payment-main-store\"]').getAttribute('href')"))->toContain('/eg/en');
});
