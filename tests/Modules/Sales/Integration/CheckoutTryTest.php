<?php

declare(strict_types=1);

use App\Http\ProblemDetails;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Inventory\Public\Events\HoldsShort;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Domain\Exception\TooManyPaymentSessions;
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
use Modules\Payments\Public\Enums\PaymentState;
use Modules\Payments\Public\Events\PaymentFailed;
use Modules\Payments\Public\Events\PaymentNotAccepted;
use Modules\Payments\Public\Events\PaymentNotTaken;
use Modules\Payments\Public\Events\PaymentSucceeded;
use Modules\Sales\Application\Checkout\QuoteComparison;
use Modules\Sales\Application\Checkout\TryCompletion;
use Modules\Sales\Application\Checkout\TryRelease;
use Modules\Sales\Application\Checkout\TryReview;
use Modules\Sales\Application\Command\AddCartLine\AddCartLine;
use Modules\Sales\Application\Command\AddCartLine\AddCartLineHandler;
use Modules\Sales\Application\Command\ChangeCartLine\ChangeCartLine;
use Modules\Sales\Application\Command\ChangeCartLine\ChangeCartLineHandler;
use Modules\Sales\Application\Command\DeleteExpiredQuotes\DeleteExpiredQuotes;
use Modules\Sales\Application\Command\DeleteExpiredQuotes\DeleteExpiredQuotesHandler;
use Modules\Sales\Application\Command\FollowCheckoutPayment\FollowCheckoutPayment;
use Modules\Sales\Application\Command\FollowCheckoutPayment\FollowCheckoutPaymentHandler;
use Modules\Sales\Application\Command\OpenCheckoutPayment\OpenCheckoutPayment;
use Modules\Sales\Application\Command\OpenCheckoutPayment\OpenCheckoutPaymentHandler;
use Modules\Sales\Application\Command\PayOnline\OnlineStart;
use Modules\Sales\Application\Command\PayOnline\PayOnline;
use Modules\Sales\Application\Command\PayOnline\PayOnlineHandler;
use Modules\Sales\Application\Command\ReturnToCheckout\ReturnToCheckout;
use Modules\Sales\Application\Command\ReturnToCheckout\ReturnToCheckoutHandler;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCoupon;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCouponHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\Command\VoidMoneyRecord\VoidMoneyRecord;
use Modules\Sales\Application\Command\VoidMoneyRecord\VoidMoneyRecordHandler;
use Modules\Sales\Application\Query\PrintOrder\PrintOrder;
use Modules\Sales\Application\Query\PrintOrder\PrintOrderHandler;
use Modules\Sales\Application\Query\ViewOrder\ViewOrder;
use Modules\Sales\Application\Query\ViewOrder\ViewOrderHandler;
use Modules\Sales\Application\Quote\QuoteView;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\CheckoutTryNotFound;
use Modules\Sales\Domain\Exception\OnlinePaymentNotVoidable;
use Modules\Sales\Domain\Exception\PaymentInProgress;
use Modules\Sales\Domain\Exception\QuoteChanged;
use Modules\Sales\Domain\Exception\QuoteExpired;
use Modules\Sales\Domain\Exception\QuoteNotFound;
use Modules\Sales\Domain\Exception\TestOrderNotShippable;
use Modules\Sales\Domain\Exception\TooManyPaymentTries;
use Modules\Sales\Domain\Exception\WayToPayNotOffered;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Infrastructure\Listener\CompleteTryWhenPaid;
use Modules\Sales\Infrastructure\Listener\FlagShortOrders;
use Modules\Sales\Infrastructure\Listener\ReleaseTryWhenNotAccepted;
use Modules\Sales\Infrastructure\Listener\ReleaseTryWhenNotPaid;
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Enums\WayToPay;
use Modules\Sales\Public\Events\OrderPlaced;
use Modules\Sales\Public\Events\PaymentRecorded;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;
use Tests\Modules\Payments\Support\FakeMyFatoorah;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Paying online (sales.md §9.5 #4–#7, #11, #14, #15; payments.md §1.4): no order until it is paid. Pay reserves
| everything under the order's future id; a paid try makes its order once, numbered then; anything else gives
| everything back with nothing counted.
*/

uses(RefreshDatabase::class);

const TRIES_NOW = '2027-01-01T12:00:00Z';

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    app()->instance(KeyTester::class, new class implements KeyTester
    {
        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void {}
    });
    Sleep::fake();
    triesAt('+0 minutes');
    RateLimiter::clear('payments-checks');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

function triesAt(string $when): void
{
    $moment = CarbonImmutable::parse(TRIES_NOW)->modify($when);
    CarbonImmutable::setTestNow($moment);
    Carbon::setTestNow($moment);
}

function triesGateway(string $mode = 'LIVE', string $storeCode = 'sa'): void
{
    Cx::asSuperAdmin(function () use ($mode, $storeCode): void {
        $store = Fx::storeId($storeCode);
        app(ChangeKeysHandler::class)->handle(new ChangeKeys($store, $mode, 'sk_key_ABC123', 'whsec_secret_1', '2030-12-31'));
        app(SwitchGatewayHandler::class)->handle(new SwitchGateway($store, true));
    });
}

/**
 * @return array{fake: FakeMyFatoorah, address: string, variants: list<string>, customer: string, product: string}
 */
function triesReady(string $mode = 'LIVE'): array
{
    $fake = FakeMyFatoorah::install();
    triesGateway($mode);

    return ['fake' => $fake, ...Cx::ready()];
}

function triesQuote(string $address, bool $usePoints = false): QuoteView
{
    return Cx::start($address, 'ONLINE', $usePoints);
}

function triesSession(QuoteView $quote): string
{
    return app(OpenCheckoutPaymentHandler::class)->handle(new OpenCheckoutPayment(Fx::storeId('sa'), $quote->id, 'en'))->sessionId;
}

function triesPay(QuoteView $quote, ?string $session = null): OnlineStart
{
    return app(PayOnlineHandler::class)->handle(new PayOnline(Fx::storeId('sa'), $quote->id, $session ?? triesSession($quote), 'en', 'https://shop.example.test/return'));
}

function triesRow(string $tryId): stdClass
{
    return DB::table('sales.checkout_tries')->where('id', $tryId)->sole();
}

function triesFollow(string $tryId): mixed
{
    return app(FollowCheckoutPaymentHandler::class)->handle(new FollowCheckoutPayment($tryId));
}

function triesAmount(QuoteView $quote): string
{
    return $quote->totals->orderTotal->toDecimal(2);
}

function triesPaymentId(): string
{
    return (string) DB::table('payments.transactions')->orderBy('id')->value('id');
}

/** Pay's process dies after its commit, before the charge is claimed: every other call goes through. */
function triesChargeCrashes(): void
{
    app()->instance(PaymentsApi::class, new class(app(PaymentsApi::class)) implements PaymentsApi
    {
        public function __construct(private PaymentsApi $real) {}

        public function availability(StoreId $store, PaymentAudience $audience): OnlinePaymentDto
        {
            return $this->real->availability($store, $audience);
        }

        public function openSession(SessionRequestDto $request): PaymentSessionDto
        {
            return $this->real->openSession($request);
        }

        public function prepare(PayRequestDto $request): string
        {
            return $this->real->prepare($request);
        }

        public function charge(string $attemptId): PaymentStartDto
        {
            throw new RuntimeException('The process died.');
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
}

/** What a released try, or one never started, leaves behind: nothing held, nothing counted, no order. */
function triesNothingHeld(): void
{
    expect(DB::table('sales.orders')->count())->toBe(0)
        ->and(DB::table('inventory.holds')->whereNull('ended_at')->count())->toBe(0)
        ->and(DB::table('promotions.coupon_reservations')->count())->toBe(0)
        ->and(DB::table('promotions.coupon_uses')->count())->toBe(0)
        ->and(DB::table('loyalty.redemption_holds')->count())->toBe(0);
}

function triesOutbox(string $type): int
{
    return DB::table('public.outbox_messages')->where('type', $type)->count();
}

/** The latest `PaymentSucceeded` as it went through the outbox. */
function triesSucceeded(): PaymentSucceeded
{
    $row = DB::table('public.outbox_messages')->where('type', PaymentSucceeded::class)->orderByDesc('id')->first() ?? throw new LogicException('No such event.');
    /** @var array{eventId: string, paymentId: string, orderId: string, storeId: string, occurredAt: string} $payload */
    $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);

    return new PaymentSucceeded($payload['eventId'], $payload['paymentId'], $payload['orderId'], $payload['storeId'], CarbonImmutable::parse($payload['occurredAt']));
}

describe('the ways to pay', function () {
    it('offers an individual online first only while the store takes it', function () {
        ['address' => $address] = Cx::ready();

        expect(Cx::start($address)->waysToPay)->toBe([WayToPay::StaffContact]);

        FakeMyFatoorah::install();
        triesGateway();
        expect(Cx::start($address)->waysToPay)->toBe([WayToPay::Online, WayToPay::StaffContact]);
    });

    it('refuses placing an online quote, which is paid first', function () {
        ['address' => $address] = triesReady();
        $quote = triesQuote($address);

        expect(fn () => Cx::place($quote->id))->toThrow(WayToPayNotOffered::class);
        triesNothingHeld();
    });

    it('opens a form that outlasts the quote by the window and the wait, only for an online quote', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);

        $session = app(OpenCheckoutPaymentHandler::class)->handle(new OpenCheckoutPayment(Fx::storeId('sa'), $quote->id, 'en'));

        expect(CarbonImmutable::instance($session->expiresAt)->equalTo(CarbonImmutable::instance($quote->expiresAt)->addMinutes(5 + 2)))->toBeTrue()
            ->and($fake->count('POST', '/v3/sessions'))->toBe(1)
            ->and(fn () => app(OpenCheckoutPaymentHandler::class)->handle(new OpenCheckoutPayment(Fx::storeId('sa'), Cx::start($address)->id, 'en')))->toThrow(WayToPayNotOffered::class);
    });
});

describe('the same quote', function () {
    it('keeps one quote and one session while nothing changed, however often the page is opened', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $first = triesQuote($address);
        $session = triesSession($first);
        triesAt('+9 minutes');

        foreach (range(1, 40) as $ignored) {
            $again = triesQuote($address);
            expect($again->id)->toBe($first->id)
                ->and(triesSession($again))->toBe($session);
        }

        expect(DB::table('sales.quotes')->count())->toBe(1)
            ->and($fake->count('POST', '/v3/sessions'))->toBe(1);
    });

    it('writes a new quote once what it asks or what it shows has changed', function (Closure $change) {
        ['address' => $address, 'variants' => $variants] = triesReady();
        $first = triesQuote($address);

        $second = $change($address, $variants);

        expect($second->id)->not->toBe($first->id);
    })->with([
        'the cart' => [function (string $address, array $variants): QuoteView {
            Cx::add($variants[0], 1);

            return triesQuote($address);
        }],
        'the way to pay' => [fn (string $address): QuoteView => Cx::start($address)],
        'the price' => [function (string $address, array $variants): QuoteView {
            Rx::set('sa', [$variants[0] => '90.00']);

            return triesQuote($address);
        }],
        'under five minutes left' => [function (string $address): QuoteView {
            triesAt('+10 minutes 1 second');

            return triesQuote($address);
        }],
    ]);
});

describe('the limit on the form', function () {
    it('counts only a new session: refreshing checkout never uses it up', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $first = triesSession($quote);

        foreach (range(1, 40) as $refresh) {
            expect(triesSession($quote))->toBe($first);
        }

        expect($fake->count('POST', '/v3/sessions'))->toBe(1);
    });

    it('stops new sessions past 30 an hour for one buyer, as a 429', function () {
        ['address' => $address, 'customer' => $customer] = triesReady();
        foreach (range(1, 30) as $hit) {
            RateLimiter::hit("payments:open-session:{$customer}", 3600);
        }

        $refused = Pr::thrown(fn () => triesSession(triesQuote($address)), TooManyPaymentSessions::class);

        expect(ProblemDetails::status($refused->category()))->toBe(429);
    });
});

describe('Pay', function () {
    it('reserves everything under the future order, with no order and no number, then charges', function () {
        ['address' => $address, 'fake' => $fake, 'customer' => $customer, 'variants' => $variants] = triesReady();
        $quote = triesQuote($address);

        $paid = triesPay($quote);

        $try = triesRow($paid->tryId);
        expect($try->state)->toBe('LIVE')
            ->and($try->customer_id)->toBe($customer)
            ->and($try->quote_id)->toBe($quote->id)
            ->and((int) $try->amount_minor)->toBe($quote->totals->orderTotal->minorUnits)
            ->and(json_decode((string) $try->address, true)['id'])->toBe($address)
            ->and(json_decode((string) $try->contact, true)['name'])->toBe('Sara Ali')
            ->and($paid->start->state)->toBe(PaymentState::InProgress)
            ->and($paid->start->frameUrl)->toBe(FakeMyFatoorah::FRAME_URL)
            ->and($fake->count('POST', '/v3/payments'))->toBe(1)
            ->and(DB::table('sales.orders')->count())->toBe(0)
            ->and(DB::table('inventory.holds')->where('order_id', $try->order_id)->count())->toBeGreaterThan(0)
            ->and(DB::table('sales.cart_lines')->count())->toBe(2)
            ->and(triesOutbox(OrderPlaced::class))->toBe(0)
            // No number taken: a try that never pays leaves no gap (§9.5 #15).
            ->and(DB::table('payments.attempts')->where('owner_id', $paid->tryId)->value('order_id'))->toBe($try->order_id);
        expect($variants)->toHaveCount(2);
    });

    it('holds the coupon and the points too, counted toward their limits while reserved', function () {
        ['address' => $address, 'customer' => $customer] = triesReady();
        Pr::ceiling(100);
        Pr::coupon('SUMMER-15');
        Lx::programme();
        Lx::deliver($customer, 1_000_00);
        Fx::actAsCustomer($customer);
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
        $quote = triesQuote($address, true);
        expect($quote->pointsUsed)->toBeGreaterThan(0);

        triesPay($quote);

        expect(DB::table('promotions.coupon_reservations')->count())->toBe(1)
            ->and(DB::table('promotions.coupon_uses')->count())->toBe(0)
            ->and(DB::table('loyalty.redemption_holds')->count())->toBe(1);
    });

    it('answers a double click with the same try and no second charge, and another window with in progress', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $session = triesSession($quote);
        $first = triesPay($quote, $session);

        $again = triesPay($quote, $session);

        expect($again->tryId)->toBe($first->tryId)
            ->and($fake->count('POST', '/v3/payments'))->toBe(1)
            ->and(DB::table('sales.checkout_tries')->count())->toBe(1)
            ->and(fn () => triesPay($quote, 'KWT-another-session'))->toThrow(PaymentInProgress::class);

        // The cart is taken: no other way of ordering it, and no form, while it is.
        $other = Cx::start($address);
        expect(fn () => Cx::place($other->id))->toThrow(PaymentInProgress::class)
            ->and(fn () => triesSession(triesQuote($address)))->toThrow(PaymentInProgress::class);
    });

    it('refuses a quote made before the cart last changed: nothing reserved', function () {
        ['address' => $address, 'variants' => $variants] = triesReady();
        $quote = triesQuote($address);
        $session = triesSession($quote);
        app(ChangeCartLineHandler::class)->handle(new ChangeCartLine(Fx::storeId('sa'), $variants[0], 'RETAIL', 3));

        expect(Pr::thrown(fn () => triesPay($quote, $session), QuoteChanged::class)->reason)->toBe('cart');
        triesNothingHeld();
        expect(DB::table('sales.checkout_tries')->count())->toBe(0)
            ->and(DB::table('payments.attempts')->count())->toBe(0);
    });

    it('builds the quote again and names what moved: nothing reserved, the customer stays on checkout', function (Closure $change, string $reason) {
        $ready = triesReady();
        $quote = triesQuote($ready['address']);
        $session = triesSession($quote);
        $change($ready);
        Fx::actAsCustomer($ready['customer']);

        expect(Pr::thrown(fn () => triesPay($quote, $session), QuoteChanged::class)->reason)->toBe($reason);
        triesNothingHeld();
        expect(DB::table('payments.attempts')->count())->toBe(0);
    })->with([
        'a price' => [fn (array $ready) => Rx::set('sa', [$ready['variants'][0] => '90.00']), 'prices'],
        'a gift now reached' => [fn (array $ready) => Pr::giftLevel($ready['variants'][1], 100_00), 'gift'],
        'the shipping' => [fn () => Cx::serve('sa', 3000), 'shipping'],
    ]);

    it('names as moved whatever the customer pays or gets, and no moment or forecast', function (Closure $change, ?string $moved) {
        ['address' => $address] = triesReady();
        $quote = triesQuote($address);
        $result = $quote->toArray();
        $change($result);
        $now = QuoteView::fromArray($quote->id, $quote->storeId, $quote->customerId, $quote->addressId, WayToPay::Online, $quote->expiresAt, $quote->createdAt, $result);

        expect(QuoteComparison::moved($quote, $now))->toBe($moved);
    })->with([
        'nothing' => [function (array &$r): void {}, null],
        'the moment prices were read' => [function (array &$r): void {
            $r['prices']['pricedAt'] = '2027-01-01T13:00:00.000000+00:00';
        }, null],
        'the points it would earn' => [function (array &$r): void {
            $r['earningPreview'] = 99;
        }, null],
        'when a sale ends' => [function (array &$r): void {
            $r['prices']['lines'][0]['endsAt'] = '2027-02-01T00:00:00.000000+00:00';
        }, null],
        'a line' => [function (array &$r): void {
            $r['prices']['lines'][0]['quantity'] = 9;
        }, 'prices'],
        'the coupon' => [function (array &$r): void {
            $r['coupon']['id'] = '01arz3ndektsv4rrffq69g5fav';
        }, 'coupon'],
        'the points used' => [function (array &$r): void {
            $r['points']['used'] = 5;
        }, 'points'],
        'the gift' => [function (array &$r): void {
            $r['gift']['levelId'] = '01arz3ndektsv4rrffq69g5fav';
        }, 'gift'],
        'the carrier' => [function (array &$r): void {
            $r['shipping']['carrierId'] = '01arz3ndektsv4rrffq69g5fav';
        }, 'shipping'],
        'the place' => [function (array &$r): void {
            $r['shipping']['destination']['cityId'] = '01arz3ndektsv4rrffq69g5fav';
        }, 'shipping'],
        'the carrier\'s price, the fee the same' => [function (array &$r): void {
            $r['shipping']['price'] = 9900;
        }, 'shipping'],
        'the free-above amount' => [function (array &$r): void {
            $r['shipping']['freeAbove'] = 1;
        }, 'shipping'],
        'the total' => [function (array &$r): void {
            $r['totals']['order'] = 1;
        }, 'totals'],
    ]);

    it('refuses a quote past its time, and one not for paying online: nothing reserved', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $session = triesSession($quote);
        $late = CarbonImmutable::instance($quote->expiresAt)->addSecond();
        CarbonImmutable::setTestNow($late);
        Carbon::setTestNow($late);

        expect(fn () => triesPay($quote, $session))->toThrow(QuoteExpired::class)
            ->and(fn () => triesPay(Cx::start($address), $session))->toThrow(WayToPayNotOffered::class)
            ->and(DB::table('sales.checkout_tries')->count())->toBe(0)
            ->and($fake->count('POST', '/v3/payments'))->toBe(0);
        triesNothingHeld();
    });

    it('never pays a second time on a quote already paid, even with the same cart again', function () {
        ['address' => $address, 'fake' => $fake, 'variants' => $variants] = triesReady();
        $quote = triesQuote($address);
        $session = triesSession($quote);
        $paid = triesPay($quote, $session);
        $fake->paid(triesAmount($quote));
        triesFollow($paid->tryId);
        Cx::add($variants[0], 2);
        Cx::add($variants[1], 1);
        DB::table('sales.quotes')->where('id', $quote->id)->update(['cart_version' => DB::table('sales.carts')->value('version')]);

        expect(fn () => triesPay($quote, $session))->toThrow(QuoteChanged::class)
            ->and($fake->count('POST', '/v3/payments'))->toBe(1)
            ->and(DB::table('sales.checkout_tries')->count())->toBe(1);
    });

    it('holds the gift\'s piece too', function () {
        ['address' => $address] = triesReady();
        ['variants' => [$gift]] = Px::ready(['90 cm']);
        Rx::switchOn('sa', [$gift]);
        Rx::set('sa', [$gift => '10.00']);
        Ix::count('sa', [$gift => 5]);
        Pr::giftLevel($gift, 1_00);
        $quote = triesQuote($address);

        $paid = triesPay($quote);

        expect($quote->giftVariantId)->toBe($gift)
            ->and(DB::table('inventory.hold_lines')->join('inventory.holds', 'holds.id', '=', 'hold_lines.hold_id')->where('holds.order_id', triesRow($paid->tryId)->order_id)->where('hold_lines.variant_id', $gift)->count())->toBe(1);
    });

    it('turns a reservation refused at the last moment into what changed, with everything rolled back', function () {
        ['address' => $address, 'variants' => $variants] = triesReady();
        $quote = triesQuote($address);
        $session = triesSession($quote);
        Ix::count('sa', [$variants[0] => 0]);

        expect(fn () => triesPay($quote, $session))->toThrow(QuoteChanged::class);
        triesNothingHeld();
        expect(DB::table('sales.checkout_tries')->count())->toBe(0)
            ->and(DB::table('payments.attempts')->count())->toBe(0);
    });

    it('releases at once when the gateway refuses the charge, so Pay can be pressed again', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $fake->charges = [fn () => Http::response(['IsSuccess' => false, 'Message' => 'SessionId is expired!', 'Errors' => []], 400)];
        $quote = triesQuote($address);

        $paid = triesPay($quote);

        expect($paid->start->state)->toBe(PaymentState::Failed)
            ->and(triesRow($paid->tryId)->state)->toBe('RELEASED');
        triesNothingHeld();
    });

    it('refuses another customer\'s quote, at the form and at Pay', function () {
        ['address' => $address] = triesReady();
        $quote = triesQuote($address);
        $session = triesSession($quote);
        Fx::actAsCustomer(Cx::customer());

        expect(fn () => triesSession($quote))->toThrow(QuoteNotFound::class)
            ->and(fn () => triesPay($quote, $session))->toThrow(QuoteNotFound::class)
            ->and(DB::table('sales.checkout_tries')->count())->toBe(0);
    });

    it('stops at the limit per customer too, over all their carts', function () {
        ['address' => $address, 'customer' => $customer] = triesReady();
        $quote = triesQuote($address);
        $session = triesSession($quote);
        foreach (range(1, PayOnlineHandler::MOST_PER_CUSTOMER) as $hit) {
            RateLimiter::hit("sales:pay:customer:{$customer}", 3600);
        }

        expect(fn () => triesPay($quote, $session))->toThrow(TooManyPaymentTries::class)
            ->and(DB::table('sales.checkout_tries')->count())->toBe(0);
    });

    it('stops abuse without changing anything, the limit per cart first', function () {
        ['address' => $address] = triesReady();
        $quote = triesQuote($address);
        $session = triesSession($quote);

        foreach (range(1, PayOnlineHandler::MOST_PER_CART) as $press) {
            triesPay($quote, $session);
        }

        expect(fn () => triesPay($quote, $session))->toThrow(TooManyPaymentTries::class)
            ->and(DB::table('sales.checkout_tries')->count())->toBe(1);
    });

    it('never shows one customer the try of another', function () {
        ['address' => $address] = triesReady();
        $paid = triesPay(triesQuote($address));
        Fx::actAsCustomer(Cx::customer());

        expect(fn () => triesFollow($paid->tryId))->toThrow(CheckoutTryNotFound::class);
    });
});

describe('paid', function () {
    it('makes the order once from the page: numbered now, the money recorded, the cart emptied, one email', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $fake->paid(triesAmount($quote));

        $view = triesFollow($paid->tryId);

        $order = DB::table('sales.orders')->sole();
        $money = DB::table('sales.payments')->sole();
        expect($view->state)->toBe('PAID')
            ->and($view->orderNumber)->toBe('SA-0001')
            ->and($order->id)->toBe(triesRow($paid->tryId)->order_id)
            ->and($order->quote_id)->toBe($quote->id)
            ->and($order->way_to_pay)->toBe('ONLINE')
            ->and($order->is_test)->toBeFalse()
            ->and((int) $order->paid_minor)->toBe($quote->totals->orderTotal->minorUnits)
            ->and($money->way)->toBe('ONLINE')
            ->and($money->reference)->toBe(triesPaymentId())
            ->and($money->recorded_by)->toBeNull()
            ->and($money->is_test)->toBeFalse()
            ->and(DB::table('sales.cart_lines')->count())->toBe(0)
            ->and(triesRow($paid->tryId)->state)->toBe('PAID')
            // The copies go once the order holds them.
            ->and(triesRow($paid->tryId)->address)->toBeNull()
            ->and(triesRow($paid->tryId)->contact)->toBeNull()
            ->and(triesOutbox(OrderPlaced::class))->toBe(1)
            ->and(triesOutbox(PaymentRecorded::class))->toBe(0)
            ->and(DB::table('inventory.holds')->where('order_id', $order->id)->count())->toBeGreaterThan(0);

        // The page again, and the event after it: still one order, one payment.
        triesFollow($paid->tryId);
        app(CompleteTryWhenPaid::class)->handle(triesSucceeded());

        expect(DB::table('sales.orders')->count())->toBe(1)
            ->and(DB::table('sales.payments')->count())->toBe(1)
            ->and((int) DB::table('sales.orders')->value('paid_minor'))->toBe($quote->totals->orderTotal->minorUnits)
            ->and(triesOutbox(OrderPlaced::class))->toBe(1);
    });

    it('makes the order from the event when the page never comes back, once per event', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $fake->paid(triesAmount($quote));
        triesAt('+1 minute');
        // Payments learns of it by itself; the page is gone.
        app(PaymentsApi::class)->refresh($paid->tryId);
        $event = triesSucceeded();

        app(CompleteTryWhenPaid::class)->handle($event);
        app(CompleteTryWhenPaid::class)->handle($event);

        expect(DB::table('sales.orders')->count())->toBe(1)
            ->and(DB::table('sales.payments')->count())->toBe(1)
            ->and(triesRow($paid->tryId)->state)->toBe('PAID');
    });

    it('records a second success on a paid try as a second payment, from the event and never from the page', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $fake->paid(triesAmount($quote));
        triesFollow($paid->tryId);
        $first = (array) DB::table('payments.transactions')->sole();
        unset($first['id']);
        DB::table('payments.transactions')->insert([...$first, 'gateway_payment_id' => 'second-payment']);
        $second = (string) DB::table('payments.transactions')->where('gateway_payment_id', 'second-payment')->value('id');
        $orderId = (string) triesRow($paid->tryId)->order_id;

        app(TryCompletion::class)->complete($paid->tryId, $second);
        expect(DB::table('sales.payments')->where('order_id', $orderId)->count())->toBe(1);

        app(CompleteTryWhenPaid::class)->handle(new PaymentSucceeded((string) Str::uuid(), $second, $orderId, Fx::storeId('sa'), CarbonImmutable::now()));

        expect(DB::table('sales.payments')->where('order_id', $orderId)->count())->toBe(2)
            ->and(DB::table('sales.orders')->count())->toBe(1)
            ->and(triesRow($paid->tryId)->refund_due_minor)->toBeNull();
    });

    it('confirms the coupon and the points with the number taken now', function () {
        ['address' => $address, 'customer' => $customer, 'fake' => $fake] = triesReady();
        Pr::ceiling(100);
        Pr::coupon('SUMMER-15');
        Lx::programme();
        Lx::deliver($customer, 1_000_00);
        Fx::actAsCustomer($customer);
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
        $quote = triesQuote($address, true);
        $paid = triesPay($quote);
        $fake->paid(triesAmount($quote));

        triesFollow($paid->tryId);

        $use = DB::table('promotions.coupon_uses')->sole();
        expect($use->order_number)->toBe('SA-0001')
            ->and(DB::table('promotions.coupon_reservations')->count())->toBe(0)
            ->and(DB::table('loyalty.redemption_holds')->count())->toBe(0)
            ->and(DB::table('loyalty.orders')->where('points_redeemed', '>', 0)->count())->toBe(1)
            ->and(DB::table('promotions.order_facts')->where('order_id', DB::table('sales.orders')->value('id'))->count())->toBe(1);
    });

    it('makes the order from its copies, whatever became of the address and the quote\'s time', function () {
        ['address' => $address, 'fake' => $fake, 'customer' => $customer] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        DB::table('access.addresses')->where('id', $address)->delete();
        $fake->paid(triesAmount($quote));
        triesAt('+16 minutes');
        Fx::actAsCustomer($customer);

        triesFollow($paid->tryId);

        expect(json_decode((string) DB::table('sales.orders')->value('address'), true)['formatted'] ?? null)->not->toBeNull();
    });

    it('still makes the order of a store switched off meanwhile, from the page, coupon and points confirmed', function () {
        ['address' => $address, 'fake' => $fake, 'customer' => $customer] = triesReady();
        Pr::ceiling(100);
        Pr::coupon('SUMMER-15');
        Lx::programme();
        Lx::deliver($customer, 1_000_00);
        Fx::actAsCustomer($customer);
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
        $quote = triesQuote($address, true);
        $paid = triesPay($quote);
        // The base store can never be switched off by hand: this stands for any other store.
        DB::table('platform.stores')->where('id', Fx::storeId('sa'))->update(['is_base' => false, 'is_active' => false]);
        $fake->paid(triesAmount($quote));

        $view = triesFollow($paid->tryId);

        expect($view->orderNumber)->toBe('SA-0001')
            ->and($view->firstSeen)->toBeTrue()
            ->and(DB::table('promotions.coupon_uses')->sole()->order_number)->toBe('SA-0001')
            ->and(DB::table('loyalty.redemption_holds')->count())->toBe(0)
            // The closed store's one page is shown once.
            ->and(triesFollow($paid->tryId)->firstSeen)->toBeFalse();
    });

    it('records what the gateway took, whatever was asked, with the flag', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $fake->paid('1.00');

        triesFollow($paid->tryId);

        $money = DB::table('sales.payments')->sole();
        expect((int) $money->amount_minor)->toBe(100)
            ->and($money->mismatch)->toBeTrue()
            ->and((int) DB::table('sales.orders')->value('paid_minor'))->toBe(100);
    });

    it('marks the print and the money rows of a real order as real, and tells staff of a differing amount', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $fake->paid('1.00');
        triesFollow($paid->tryId);
        $order = DB::table('sales.orders')->sole();
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']));

        $view = app(ViewOrderHandler::class)->handle(new ViewOrder((string) $order->id));

        expect($view->isTest)->toBeFalse()
            ->and($view->money[0]->isTest)->toBeFalse()
            ->and($view->money[0]->mismatch)->toBeTrue()
            ->and(app(PrintOrderHandler::class)->handle(new PrintOrder((string) $order->id))->isTest)->toBeFalse();
    });

    it('carries a "stock was reduced" flag the try received', function () {
        ['address' => $address, 'fake' => $fake, 'variants' => $variants] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $orderId = (string) triesRow($paid->tryId)->order_id;

        app(FlagShortOrders::class)->handle(new HoldsShort((string) Str::uuid(), Fx::storeId('sa'), $variants[0], [$orderId], CarbonImmutable::now()));
        expect(triesRow($paid->tryId)->short_of_stock_at)->not->toBeNull();

        $fake->paid(triesAmount($quote));
        triesFollow($paid->tryId);

        expect(DB::table('sales.orders')->value('short_of_stock_at'))->not->toBeNull()
            ->and(DB::table('sales.order_history')->where('kind', 'SHORT_FLAGGED')->count())->toBe(1);
    });

    it('flags no try already over', function () {
        ['address' => $address, 'variants' => $variants] = triesReady();
        $paid = triesPay(triesQuote($address));
        $orderId = (string) triesRow($paid->tryId)->order_id;
        app(TryRelease::class)->release($paid->tryId);

        app(FlagShortOrders::class)->handle(new HoldsShort((string) Str::uuid(), Fx::storeId('sa'), $variants[0], [$orderId], CarbonImmutable::now()));

        expect(triesRow($paid->tryId)->short_of_stock_at)->toBeNull();
    });

    it('never voids a real online payment', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $fake->paid(triesAmount($quote));
        triesFollow($paid->tryId);
        $record = (string) DB::table('sales.payments')->value('id');
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::PAYMENT_RECORD, SalesPermissions::ORDER_VIEW], ['sa']));

        expect(fn () => app(VoidMoneyRecordHandler::class)->handle(new VoidMoneyRecord('PAYMENT', $record, 'a mistake')))->toThrow(OnlinePaymentNotVoidable::class)
            ->and(DB::table('sales.payments')->value('voided_at'))->toBeNull();
    });
});

describe('not paid', function () {
    it('gives everything back on a decline, with nothing counted and the cart untouched', function () {
        ['address' => $address, 'fake' => $fake, 'customer' => $customer] = triesReady();
        Pr::ceiling(100);
        Pr::coupon('SUMMER-15');
        Lx::programme();
        Lx::deliver($customer, 1_000_00);
        Fx::actAsCustomer($customer);
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
        $quote = triesQuote($address, true);
        $paid = triesPay($quote);
        $fake->declined();

        $view = triesFollow($paid->tryId);

        expect($view->state)->toBe('RELEASED')
            ->and($view->payment)->toBe(PaymentState::Failed)
            ->and($view->declineCode)->toBe('MF003')
            ->and(triesRow($paid->tryId)->address)->toBeNull()
            ->and(DB::table('sales.cart_lines')->count())->toBe(2)
            ->and(DB::table('sales.carts')->value('coupon_code'))->toBe('SUMMER-15');
        triesNothingHeld();

        // A new session, a new try, a new five minutes (§9.6 #2).
        $fake->status = 'INPROGRESS';
        $fake->errorCode = '';
        $fake->invoiceId = '7320291';
        $again = triesPay(triesQuote($address, true));
        expect($again->tryId)->not->toBe($paid->tryId)
            ->and(triesRow($again->tryId)->state)->toBe('LIVE');
    });

    it('takes the same way from the events, only while the try is live', function (string $type) {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $orderId = (string) triesRow($paid->tryId)->order_id;
        $event = $type === PaymentFailed::class
            ? new PaymentFailed((string) Str::uuid(), '1', $orderId, CarbonImmutable::now())
            : new PaymentNotTaken((string) Str::uuid(), (string) triesRow($paid->tryId)->attempt_id, $paid->tryId, $orderId, CarbonImmutable::now());

        app(ReleaseTryWhenNotPaid::class)->handle($event);

        expect(triesRow($paid->tryId)->state)->toBe('RELEASED');
        triesNothingHeld();
        expect($fake->count('POST', '/v3/payments'))->toBe(1);
    })->with([PaymentFailed::class, PaymentNotTaken::class]);

    it('changes nothing when a release comes after the order was made', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $fake->paid(triesAmount($quote));
        triesFollow($paid->tryId);
        $orderId = (string) triesRow($paid->tryId)->order_id;

        app(ReleaseTryWhenNotPaid::class)->handle(new PaymentFailed((string) Str::uuid(), '1', $orderId, CarbonImmutable::now()));

        app(TryRelease::class)->release($paid->tryId);

        expect(triesRow($paid->tryId)->state)->toBe('PAID')
            ->and(DB::table('inventory.holds')->where('order_id', $orderId)->whereNull('ended_at')->count())->toBeGreaterThan(0);
    });

    it('makes no order for a success after release: a refund due, once per event', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $orderId = (string) triesRow($paid->tryId)->order_id;
        app(ReleaseTryWhenNotPaid::class)->handle(new PaymentNotTaken((string) Str::uuid(), (string) triesRow($paid->tryId)->attempt_id, $paid->tryId, $orderId, CarbonImmutable::now()));
        $fake->paid(triesAmount($quote));
        app(PaymentsApi::class)->refresh($paid->tryId);
        $event = triesSucceeded();

        // The page's own answer records nothing: only the event does, once.
        triesFollow($paid->tryId);
        app(CompleteTryWhenPaid::class)->handle($event);
        app(CompleteTryWhenPaid::class)->handle($event);

        $try = triesRow($paid->tryId);
        expect($try->state)->toBe('RELEASED')
            ->and((int) $try->refund_due_minor)->toBe($quote->totals->orderTotal->minorUnits)
            ->and($try->needs_attention)->toBeTrue()
            ->and(DB::table('sales.orders')->count())->toBe(0);
        triesNothingHeld();
    });

    it('leaves the refund to the event when the page\'s answer comes for a try already released', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        app(TryRelease::class)->release($paid->tryId);
        $fake->paid(triesAmount($quote));
        app(PaymentsApi::class)->refresh($paid->tryId);

        app(TryCompletion::class)->complete($paid->tryId, triesPaymentId());

        expect(triesRow($paid->tryId)->refund_due_minor)->toBeNull()
            ->and(triesRow($paid->tryId)->state)->toBe('RELEASED');
    });

    it('releases a currency refused as soon as the page learns of it, before any event', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $fake->paid(triesAmount($quote));
        $fake->displayCurrency = 'USD';

        triesFollow($paid->tryId);

        expect(triesRow($paid->tryId)->state)->toBe('RELEASED')
            ->and((int) triesRow($paid->tryId)->refund_due_minor)->toBe($quote->totals->orderTotal->minorUnits)
            ->and(DB::table('sales.orders')->count())->toBe(0);
        triesNothingHeld();
    });

    it('never doubles the refund due of a currency refused, however often it is told', function () {
        ['address' => $address] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);

        app(TryRelease::class)->notAccepted($paid->tryId);
        app(TryRelease::class)->notAccepted($paid->tryId);

        expect((int) triesRow($paid->tryId)->refund_due_minor)->toBe($quote->totals->orderTotal->minorUnits);
    });

    it('releases a currency refused with a refund due of what was asked, once', function () {
        ['address' => $address] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $row = triesRow($paid->tryId);
        $event = new PaymentNotAccepted((string) Str::uuid(), (string) $row->attempt_id, $paid->tryId, (string) $row->order_id, Fx::storeId('sa'), CarbonImmutable::now());

        app(ReleaseTryWhenNotAccepted::class)->handle($event);
        app(ReleaseTryWhenNotAccepted::class)->handle($event);

        expect(triesRow($paid->tryId)->state)->toBe('RELEASED')
            ->and((int) triesRow($paid->tryId)->refund_due_minor)->toBe($quote->totals->orderTotal->minorUnits);
        triesNothingHeld();
    });

    it('makes nothing of an event for a payment request\'s own order: a try beside it is untouched', function () {
        ['address' => $address] = triesReady();
        $paid = triesPay(triesQuote($address));
        $other = strtolower((string) Str::ulid());

        app(ReleaseTryWhenNotPaid::class)->handle(new PaymentFailed((string) Str::uuid(), '1', $other, CarbonImmutable::now()));
        app(ReleaseTryWhenNotAccepted::class)->handle(new PaymentNotAccepted((string) Str::uuid(), strtolower((string) Str::ulid()), strtolower((string) Str::ulid()), $other, Fx::storeId('sa'), CarbonImmutable::now()));
        app(CompleteTryWhenPaid::class)->handle(new PaymentSucceeded((string) Str::uuid(), '1', $other, Fx::storeId('sa'), CarbonImmutable::now()));

        expect(triesRow($paid->tryId)->state)->toBe('LIVE')
            ->and(triesRow($paid->tryId)->refund_due_minor)->toBeNull()
            ->and(DB::table('sales.orders')->count())->toBe(0);
    });
});

describe('checkout opened again', function () {
    it('says until when a charge made can still go through, the cart free to change, and leaves it live', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $paid = triesPay(triesQuote($address));

        $view = app(ReturnToCheckoutHandler::class)->handle(new ReturnToCheckout(Fx::storeId('sa')));

        expect($view?->tryId)->toBe($paid->tryId)
            ->and($view?->state)->toBe('LIVE')
            ->and($view?->holdEndsAt)->not->toBeNull()
            ->and(triesRow($paid->tryId)->state)->toBe('LIVE')
            ->and($fake->count('POST', '/v3/payments'))->toBe(1);
    });

    it('answers nothing when the charge made turns out declined: the customer simply stays on checkout', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $paid = triesPay(triesQuote($address));
        $fake->declined();
        app(PaymentsApi::class)->refresh($paid->tryId);

        $view = app(ReturnToCheckoutHandler::class)->handle(new ReturnToCheckout(Fx::storeId('sa')));

        expect($view)->toBeNull()
            ->and(triesRow($paid->tryId)->state)->toBe('RELEASED');
        triesNothingHeld();
    });

    it('never asks the gateway on the way back: the page stays quick while it is away, and its own follow asks', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $paid = triesPay(triesQuote($address));
        $fake->declined();
        $before = count($fake->calls);

        $view = app(ReturnToCheckoutHandler::class)->handle(new ReturnToCheckout(Fx::storeId('sa')));

        expect(count($fake->calls))->toBe($before)
            ->and($view?->state)->toBe('LIVE');

        triesFollow($paid->tryId);

        expect(count($fake->calls))->toBeGreaterThan($before)
            ->and(triesRow($paid->tryId)->state)->toBe('RELEASED');
    });

    it('releases there and then a try whose charge was never sent: as if Pay had never been pressed', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $session = triesSession($quote);
        triesChargeCrashes();
        expect(fn () => triesPay($quote, $session))->toThrow(RuntimeException::class);
        $try = DB::table('sales.checkout_tries')->sole();
        expect($try->state)->toBe('LIVE')
            ->and(DB::table('inventory.holds')->whereNull('ended_at')->count())->toBe(1);

        $view = app(ReturnToCheckoutHandler::class)->handle(new ReturnToCheckout(Fx::storeId('sa')));

        expect($view)->toBeNull()
            ->and(triesRow((string) $try->id)->state)->toBe('RELEASED')
            ->and(DB::table('payments.attempts')->where('owner_id', $try->id)->value('state'))->toBe('ABANDONED')
            ->and($fake->count('POST', '/v3/payments'))->toBe(0);
        triesNothingHeld();
    });

    it('tells of an order the event made while they were away, once, when they come back', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $fake->paid(triesAmount($quote));
        app(PaymentsApi::class)->refresh($paid->tryId);
        app(CompleteTryWhenPaid::class)->handle(triesSucceeded());
        expect(triesRow($paid->tryId)->state)->toBe('PAID');

        $view = app(ReturnToCheckoutHandler::class)->handle(new ReturnToCheckout(Fx::storeId('sa')));

        expect($view?->orderNumber)->toBe('SA-0001')
            ->and($view?->firstSeen)->toBeTrue()
            ->and(app(ReturnToCheckoutHandler::class)->handle(new ReturnToCheckout(Fx::storeId('sa'))))->toBeNull();
    });

    it('answers their new order once paid, and nothing with no try', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();

        expect(app(ReturnToCheckoutHandler::class)->handle(new ReturnToCheckout(Fx::storeId('sa'))))->toBeNull();

        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $fake->paid(triesAmount($quote));
        // The gateway has told of it, by a notice or a check.
        app(PaymentsApi::class)->refresh($paid->tryId);

        $view = app(ReturnToCheckoutHandler::class)->handle(new ReturnToCheckout(Fx::storeId('sa')));

        expect($view?->state)->toBe('PAID')
            ->and($view?->orderNumber)->toBe('SA-0001')
            ->and($paid->tryId)->toBe($view?->tryId);
    });
});

describe('the hourly look', function () {
    it('completes a paid try and releases a dead one, leaving those still in their window', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $fake->paid(triesAmount($quote));

        // Still inside its window: not looked at.
        app(TryReview::class)->run();
        expect(triesRow($paid->tryId)->state)->toBe('LIVE');

        triesAt('+6 minutes');
        app(TryReview::class)->run();

        expect(triesRow($paid->tryId)->state)->toBe('PAID')
            ->and(DB::table('sales.orders')->count())->toBe(1);
    });

    it('releases a try declined with no event yet, and one whose charge was never sent', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $fake->declined();
        triesAt('+6 minutes');

        app(TryReview::class)->run();

        expect(triesRow($paid->tryId)->state)->toBe('RELEASED');
        triesNothingHeld();

        $fake->status = 'INPROGRESS';
        $fake->errorCode = '';
        $next = triesQuote($address);
        $session = triesSession($next);
        triesChargeCrashes();
        expect(fn () => triesPay($next, $session))->toThrow(RuntimeException::class);
        triesAt('+12 minutes');

        app(TryReview::class)->run();

        expect(DB::table('sales.checkout_tries')->where('state', 'LIVE')->count())->toBe(0);
        triesNothingHeld();
    });

    it('marks what it cannot settle as needing attention, here and in Payments, and goes on to the next', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $fake->paid(triesAmount($quote));
        // The order cannot be made: its copy is unreadable.
        DB::table('sales.checkout_tries')->where('id', $paid->tryId)->update(['contact' => '{}']);
        // Another customer's try, past its window too, with nothing held: its own failure, reached all the same.
        $other = Cx::customer();
        $secondId = strtolower((string) Str::ulid());
        $attemptId = strtolower((string) Str::ulid());
        DB::table('payments.attempts')->insert([...(array) DB::table('payments.attempts')->where('owner_id', $paid->tryId)->sole(), 'id' => $attemptId, 'owner_id' => $secondId, 'order_id' => strtolower((string) Str::ulid()), 'session_id' => 'KWT-second', 'state' => 'EXPIRED', 'invoice_id' => null, 'gateway_payment_id' => null, 'idempotency_key' => (string) Str::uuid(), 'next_check_at' => null]);
        DB::table('sales.checkout_tries')->insert([...(array) triesRow($paid->tryId), 'id' => $secondId, 'customer_id' => $other, 'cart_id' => strtolower((string) Str::ulid()), 'order_id' => strtolower((string) Str::ulid()), 'quote_id' => strtolower((string) Str::ulid()), 'attempt_id' => $attemptId, 'contact' => '{}', 'window_ends_at' => CarbonImmutable::now()->addMinutes(1)]);
        triesAt('+6 minutes');

        app(TryReview::class)->run();

        expect(triesRow($paid->tryId)->state)->toBe('LIVE')
            ->and(triesRow($paid->tryId)->needs_attention)->toBeTrue()
            ->and(DB::table('payments.attempts')->where('owner_id', $paid->tryId)->value('needs_attention'))->toBeTrue()
            ->and(triesRow($secondId)->needs_attention)->toBeTrue();
    });

    it('releases a try at its hold limit with the gateway still away', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $paid = triesPay(triesQuote($address));
        $fake->statusAnswer = fn () => throw new ConnectionException('away');
        $hold = app(PaymentsApi::class)->timers(StoreId::fromString(Fx::storeId('sa')))->hold;
        triesAt('+'.($hold + 1).' minutes');

        app(TryReview::class)->run();

        expect(triesRow($paid->tryId)->state)->toBe('RELEASED');
        triesNothingHeld();
    });

    it('marks a try still live past its hold limit, here and in Payments', function () {
        ['address' => $address] = triesReady();
        $paid = triesPay(triesQuote($address));
        $attemptId = (string) triesRow($paid->tryId)->attempt_id;
        triesAt('+20 minutes');
        $past = CarbonImmutable::now()->subMinute();
        $payments = Cx::paymentsStandIn();
        $payments->shouldReceive('abandon')->andReturn(false);
        $payments->shouldReceive('refresh')->andReturn(new PaymentStateDto(PaymentState::InProgress, $past, $past, $past, false, $attemptId));
        $payments->shouldReceive('needsAttention')->once()->with($attemptId);
        app()->instance(PaymentsApi::class, $payments);

        app(TryReview::class)->run();

        expect(triesRow($paid->tryId)->state)->toBe('LIVE')
            ->and(triesRow($paid->tryId)->needs_attention)->toBeTrue();
    });

    it('leaves unmarked a try within its hold limit while the gateway is away', function () {
        ['address' => $address, 'fake' => $fake] = triesReady();
        $paid = triesPay(triesQuote($address));
        $fake->statusAnswer = fn () => throw new ConnectionException('away');
        triesAt('+6 minutes');

        app(TryReview::class)->run();

        expect(triesRow($paid->tryId)->state)->toBe('LIVE')
            ->and(triesRow($paid->tryId)->needs_attention)->toBeFalse();
    });
});

describe('test mode', function () {
    it('numbers a test order apart, marks it and its money, and never ships it', function () {
        ['address' => $address, 'fake' => $fake] = triesReady('TEST');
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $fake->paid(triesAmount($quote));

        $view = triesFollow($paid->tryId);

        $order = DB::table('sales.orders')->sole();
        expect($view->orderNumber)->toBe('TEST-0001')
            ->and($order->is_test)->toBeTrue()
            ->and(DB::table('sales.payments')->value('is_test'))->toBeTrue()
            ->and(DB::table('promotions.order_facts')->count())->toBe(0);

        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_FULFIL, SalesPermissions::ORDER_VIEW], ['sa']));
        expect(fn () => app(ShipOrderHandler::class)->handle(new ShipOrder((string) $order->id, Cx::serve()['carrier'], null, 'T1', null)))->toThrow(TestOrderNotShippable::class);
    });

    it('goes by the mode the payment was made in, not the store\'s when it is paid', function () {
        ['address' => $address, 'fake' => $fake] = triesReady('TEST');
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        DB::table('payments.gateways')->update(['mode' => 'LIVE']);
        $fake->paid(triesAmount($quote));

        expect(triesFollow($paid->tryId)->orderNumber)->toBe('TEST-0001');
    });

    it('numbers any order placed while the store is in test, whatever the way to pay', function () {
        FakeMyFatoorah::install();
        triesGateway('TEST');
        ['address' => $address] = Cx::ready();

        $placed = Cx::place(Cx::start($address)->id);

        expect($placed->number)->toBe('TEST-0001')
            ->and(DB::table('sales.orders')->value('is_test'))->toBeTrue()
            ->and(DB::table('promotions.order_facts')->count())->toBe(0);

        // The real counter is untouched: the first real order is still SA-0001.
        DB::table('payments.gateways')->update(['active' => false]);
        Rx::set('sa', []);
        $real = Px::ready(['70 cm']);
        Rx::switchOn('sa', $real['variants']);
        Rx::set('sa', [$real['variants'][0] => '10.00']);
        Ix::count('sa', [$real['variants'][0] => 5]);
        Cx::add($real['variants'][0]);
        expect(Cx::place(Cx::start($address)->id)->number)->toBe('SA-0001');
    });

    it('leaves test orders out of every figure', function () {
        ['address' => $address, 'fake' => $fake] = triesReady('TEST');
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $fake->paid(triesAmount($quote));
        triesFollow($paid->tryId);

        $reports = app(SalesReports::class);
        $store = StoreId::fromString(Fx::storeId('sa'));
        [$from, $to] = [CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay()];
        $moneyDay = CarbonImmutable::parse((string) DB::table('sales.payments')->sole()->happened_on);

        expect($reports->counts($store, $from, $to)->placed)->toBe(0)
            ->and($reports->moneyOn($store, $moneyDay, $moneyDay)->payments)->toBe([])
            ->and($reports->moneyByDay($store, $moneyDay, $moneyDay))->toBe([])
            ->and(array_sum(array_map(static fn ($day): int => $day->orders, $reports->revenueByDay($store, $from, $to))))->toBe(0)
            ->and($reports->productSales($store, $from, $to)->rows)->toBe([])
            ->and($reports->productNetSales($store, $from, $to)->rows)->toBe([])
            ->and(app(Orders::class)->piecesSold($store->value, $from))->toBe([]);

        // Pieces sold leaves out an order still waiting for approval: an approved one is what proves this.
        DB::table('sales.orders')->update(['status' => OrderState::APPROVED, 'decided_at' => CarbonImmutable::now(), 'decided_by' => Fx::staff()]);
        expect(app(Orders::class)->piecesSold($store->value, $from))->toBe([]);
    });

    it('leaves a test transfer out of the transfers waiting, and marks the order and print', function () {
        ['address' => $address, 'fake' => $fake] = triesReady('TEST');
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $fake->paid(triesAmount($quote));
        triesFollow($paid->tryId);
        $order = DB::table('sales.orders')->sole();
        DB::table('sales.orders')->update(['way_to_pay' => 'BANK_TRANSFER', 'paid_minor' => 0]);

        expect(app(SalesReports::class)->transfersWaiting(StoreId::fromString(Fx::storeId('sa')))->orders)->toBe(0);

        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']));
        $view = app(ViewOrderHandler::class)->handle(new ViewOrder((string) $order->id));

        expect($view->isTest)->toBeTrue()
            ->and($view->money[0]->isTest)->toBeTrue()
            ->and(app(PrintOrderHandler::class)->handle(new PrintOrder((string) $order->id))->isTest)->toBeTrue();
    });

    it('may void a test online payment', function () {
        ['address' => $address, 'fake' => $fake] = triesReady('TEST');
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $fake->paid(triesAmount($quote));
        triesFollow($paid->tryId);
        $record = (string) DB::table('sales.payments')->value('id');
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::PAYMENT_RECORD, SalesPermissions::ORDER_VIEW], ['sa']));

        app(VoidMoneyRecordHandler::class)->handle(new VoidMoneyRecord('PAYMENT', $record, 'a test'));

        expect(DB::table('sales.payments')->value('voided_at'))->not->toBeNull();
    });
});

describe('the tables', function () {
    it('allows one live try per cart and one kept try per quote', function () {
        ['address' => $address] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        $row = (array) triesRow($paid->tryId);
        $another = ['id' => strtolower((string) Str::ulid()), 'order_id' => strtolower((string) Str::ulid())];

        expect(fn () => DB::connection()->transaction(fn () => DB::table('sales.checkout_tries')->insert([...$row, ...$another, 'quote_id' => strtolower((string) Str::ulid())])))->toThrow(QueryException::class, 'checkout_tries_live_cart')
            ->and(fn () => DB::connection()->transaction(fn () => DB::table('sales.checkout_tries')->insert([...$row, ...$another, 'cart_id' => strtolower((string) Str::ulid())])))->toThrow(QueryException::class, 'checkout_tries_kept_quote');
    });

    it('keeps a quote with a try not released through the nightly clean-up, and lets a released one go', function () {
        ['address' => $address] = triesReady();
        $quote = triesQuote($address);
        $paid = triesPay($quote);
        DB::table('sales.checkout_tries')->where('id', $paid->tryId)->update(['quote_id' => $quote->id]);
        DB::table('sales.quotes')->where('id', '<>', $quote->id)->delete();
        $other = (string) Str::ulid();
        DB::table('sales.quotes')->insert([...(array) DB::table('sales.quotes')->where('id', $quote->id)->sole(), 'id' => strtolower($other)]);
        triesAt('+1 day');

        Fx::asSystem(fn () => app(DeleteExpiredQuotesHandler::class)->handle(new DeleteExpiredQuotes));

        expect(DB::table('sales.quotes')->where('id', $quote->id)->exists())->toBeTrue()
            ->and(DB::table('sales.quotes')->where('id', strtolower($other))->exists())->toBeFalse();

        DB::table('sales.checkout_tries')->where('id', $paid->tryId)->update(['state' => 'RELEASED', 'ended_at' => CarbonImmutable::now()]);
        Fx::asSystem(fn () => app(DeleteExpiredQuotesHandler::class)->handle(new DeleteExpiredQuotes));

        expect(DB::table('sales.quotes')->where('id', $quote->id)->exists())->toBeFalse();
    });

    it('raises the cart\'s version with every change of its lines and coupon, and not for a notice', function () {
        ['variants' => $variants] = Cx::ready();
        $store = Fx::storeId('sa');
        $version = fn (): int => (int) DB::table('sales.carts')->value('version');
        $raised = function (Closure $change) use ($version): void {
            $before = $version();
            $change();
            expect($version())->toBe($before + 1);
        };

        $raised(fn () => app(ChangeCartLineHandler::class)->handle(new ChangeCartLine($store, $variants[0], 'RETAIL', 3)));
        $raised(fn () => app(AddCartLineHandler::class)->handle(new AddCartLine($store, $variants[0], 'RETAIL', 1)));
        $raised(fn () => app(Carts::class)->remove((string) DB::table('sales.carts')->value('id'), $variants[1], SaleMode::Retail, CarbonImmutable::now()));
        Pr::coupon('SUMMER-15');
        $raised(fn () => app(SetCartCouponHandler::class)->handle(new SetCartCoupon($store, 'SUMMER-15')));
        $raised(fn () => app(Carts::class)->setCoupon((string) DB::table('sales.carts')->value('id'), null, CarbonImmutable::now()));

        $before = $version();
        app(Carts::class)->setNotice((string) DB::table('sales.carts')->value('id'), [], CarbonImmutable::now());
        expect($version())->toBe($before);
    });

    it('makes the test numbers with the store, so the first two test orders never race to create them', function () {
        expect(DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS made', ['sales.test_order_number_'.Fx::storeId('sa')])->made)->toBeTrue();
    });
});
