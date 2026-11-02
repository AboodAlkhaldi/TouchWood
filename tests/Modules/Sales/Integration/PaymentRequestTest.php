<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\PaymentRequestStateDto;
use Modules\Payments\Public\Enums\PaymentRequestState;
use Modules\Payments\Public\Enums\PaymentState;
use Modules\Payments\Public\Events\PaymentRequested;
use Modules\Payments\Public\Events\PaymentSucceeded;
use Modules\Sales\Application\Command\CancelOrder\CancelOrder;
use Modules\Sales\Application\Command\CancelOrder\CancelOrderHandler;
use Modules\Sales\Application\Command\CancelOwnOrder\CancelOwnOrder;
use Modules\Sales\Application\Command\CancelOwnOrder\CancelOwnOrderHandler;
use Modules\Sales\Application\Command\EditOrder\EditOrder;
use Modules\Sales\Application\Command\EditOrder\EditOrderHandler;
use Modules\Sales\Application\Command\FollowRequestPayment\FollowRequestPayment;
use Modules\Sales\Application\Command\FollowRequestPayment\FollowRequestPaymentHandler;
use Modules\Sales\Application\Command\FollowRequestPayment\RequestPaymentView;
use Modules\Sales\Application\Command\OpenRequestPayment\OpenRequestPayment;
use Modules\Sales\Application\Command\OpenRequestPayment\OpenRequestPaymentHandler;
use Modules\Sales\Application\Command\PayOrderRequest\PayOrderRequest;
use Modules\Sales\Application\Command\PayOrderRequest\PayOrderRequestHandler;
use Modules\Sales\Application\Command\PayOrderRequest\RequestStart;
use Modules\Sales\Application\Command\RecordPayment\RecordPayment;
use Modules\Sales\Application\Command\RecordPayment\RecordPaymentHandler;
use Modules\Sales\Application\Command\RecordRefund\RecordRefund;
use Modules\Sales\Application\Command\RecordRefund\RecordRefundHandler;
use Modules\Sales\Application\Command\RequestOrderPayment\RequestOrderPayment;
use Modules\Sales\Application\Command\RequestOrderPayment\RequestOrderPaymentHandler;
use Modules\Sales\Application\Command\SetShippingFee\SetShippingFee;
use Modules\Sales\Application\Command\SetShippingFee\SetShippingFeeHandler;
use Modules\Sales\Application\Command\UploadTransferDocument\UploadTransferDocument;
use Modules\Sales\Application\Command\UploadTransferDocument\UploadTransferDocumentHandler;
use Modules\Sales\Application\Command\WithdrawOrderRequest\WithdrawOrderRequest;
use Modules\Sales\Application\Command\WithdrawOrderRequest\WithdrawOrderRequestHandler;
use Modules\Sales\Application\Order\OrderRequests;
use Modules\Sales\Application\Order\TransferOverdueCheck;
use Modules\Sales\Application\Query\ListOrders\ListOrders;
use Modules\Sales\Application\Query\ListOrders\ListOrdersHandler;
use Modules\Sales\Application\Query\ListOwnOrders\ListOwnOrders;
use Modules\Sales\Application\Query\ListOwnOrders\ListOwnOrdersHandler;
use Modules\Sales\Application\Query\Staff\StaffOrderRow;
use Modules\Sales\Application\Query\Staff\StaffOrderView;
use Modules\Sales\Application\Query\ViewOrder\ViewOrder;
use Modules\Sales\Application\Query\ViewOrder\ViewOrderHandler;
use Modules\Sales\Application\Query\ViewOwnOrder\ViewOwnOrder;
use Modules\Sales\Application\Query\ViewOwnOrder\ViewOwnOrderHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\PaymentBeingConfirmed;
use Modules\Sales\Domain\Exception\PaymentInProgress;
use Modules\Sales\Domain\Exception\PaymentLinkEnded;
use Modules\Sales\Domain\Exception\PaymentRequestNotAllowed;
use Modules\Sales\Domain\Exception\ShippingFeeNotSet;
use Modules\Sales\Domain\Exception\TooManyPaymentTries;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Infrastructure\Listener\RecordRequestPayment;
use Modules\Sales\Public\Events\PaymentRecorded;
use Modules\Sales\Public\Events\TransferOverdue;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\B2B\Support\B2BFixtures as Bx;
use Tests\Modules\Payments\Support\FakeMyFatoorah;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| A payment request (payments.md §1.5; sales.md §9.5 #7–#9, #16): staff ask for exactly what an order is missing, the
| customer pays it on its own page, and paid means an `ONLINE` payment and `PaymentRecorded`. Staff's edits, hand
| recording and the customer's cancel wait for a payment in progress. A transfer past its check time is told once.
*/

uses(RefreshDatabase::class);

const REQUESTS_NOW = '2027-01-01T12:00:00Z';

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    app()->instance(KeyTester::class, new class implements KeyTester
    {
        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void {}
    });
    Sleep::fake();
    requestsAt('+0 minutes');
    RateLimiter::clear('payments-checks');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
    File::deleteDirectory(Bx::uploads());
});

function requestsAt(string $when): void
{
    $moment = CarbonImmutable::parse(REQUESTS_NOW)->modify($when);
    CarbonImmutable::setTestNow($moment);
    Carbon::setTestNow($moment);
}

/**
 * The store's gateway on, and an individual's order placed through staff: acting as the customer.
 *
 * @return array{fake: FakeMyFatoorah, order: string, number: string, total: int, customer: string, variants: list<string>, address: string}
 */
function requestsReady(string $mode = 'LIVE'): array
{
    $fake = FakeMyFatoorah::install();
    Cx::asSuperAdmin(function () use ($mode): void {
        app(ChangeKeysHandler::class)->handle(new ChangeKeys(Fx::storeId('sa'), $mode, 'sk_key_ABC123', 'whsec_secret_1', '2030-12-31'));
        app(SwitchGatewayHandler::class)->handle(new SwitchGateway(Fx::storeId('sa'), true));
    });
    ['customer' => $customer, 'address' => $address, 'variants' => $variants] = Cx::ready();
    $placed = Cx::place(Cx::start($address)->id);

    return [
        'fake' => $fake, 'order' => $placed->id, 'number' => $placed->number, 'customer' => $customer, 'variants' => $variants, 'address' => $address,
        'total' => (int) DB::table('sales.orders')->where('id', $placed->id)->value('order_total_minor'),
    ];
}

function requestsAsk(string $order): PaymentRequestStateDto
{
    return Cx::asSuperAdmin(static fn (): PaymentRequestStateDto => app(RequestOrderPaymentHandler::class)->handle(new RequestOrderPayment($order)));
}

function requestsSession(string $number): string
{
    return app(OpenRequestPaymentHandler::class)->handle(new OpenRequestPayment(Fx::storeId('sa'), $number, 'en'))->sessionId;
}

function requestsPay(string $number, ?string $session = null): RequestStart
{
    return app(PayOrderRequestHandler::class)->handle(new PayOrderRequest(Fx::storeId('sa'), $number, $session ?? requestsSession($number), 'en', 'https://shop.example.test/return'));
}

function requestsFollow(string $number, string $requestId): RequestPaymentView
{
    return app(FollowRequestPaymentHandler::class)->handle(new FollowRequestPayment(Fx::storeId('sa'), $number, $requestId));
}

function requestsAmount(int $minor): string
{
    return number_format($minor / 100, 2, '.', '');
}

function requestsOutbox(string $type): int
{
    return DB::table('public.outbox_messages')->where('type', $type)->count();
}

function requestsState(string $requestId): string
{
    return (string) DB::table('payments.requests')->where('id', $requestId)->value('state');
}

/** The money recorded online on the order. */
function requestsOnline(string $order): int
{
    return DB::table('sales.payments')->where('order_id', $order)->where('way', 'ONLINE')->count();
}

/** The latest `PaymentSucceeded` as it went through the outbox. */
function requestsSucceeded(): PaymentSucceeded
{
    $row = DB::table('public.outbox_messages')->where('type', PaymentSucceeded::class)->orderByDesc('id')->first() ?? throw new LogicException('No such event.');
    /** @var array{eventId: string, paymentId: string, orderId: string, storeId: string, occurredAt: string} $payload */
    $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);

    return new PaymentSucceeded($payload['eventId'], $payload['paymentId'], $payload['orderId'], $payload['storeId'], CarbonImmutable::parse($payload['occurredAt']));
}

/** @param  list<string>  $variants */
function requestsEdit(string $order, array $variants, int $firstQuantity): string
{
    return Cx::asSuperAdmin(static fn (): string => app(EditOrderHandler::class)->handle(new EditOrder($order, 'Called the customer', [
        ['variantId' => $variants[0], 'mode' => 'RETAIL', 'quantity' => $firstQuantity],
        ['variantId' => $variants[1], 'mode' => 'RETAIL', 'quantity' => 1],
    ], null, 'KEEP')));
}

function requestsRecordByHand(string $order): string
{
    return Cx::asSuperAdmin(static fn (): string => app(RecordPaymentHandler::class)->handle(new RecordPayment($order, 'OTHER', null, 'Cash', '2027-01-01', (string) Str::uuid())));
}

function requestsStaffView(string $order): StaffOrderView
{
    return Cx::asSuperAdmin(static fn (): StaffOrderView => app(ViewOrderHandler::class)->handle(new ViewOrder($order)));
}

describe('asking', function () {
    it('asks exactly what is missing, for 60 minutes, told once, in the history', function () {
        ['order' => $order, 'total' => $total] = requestsReady();

        $request = requestsAsk($order);

        expect($request->amount->minorUnits)->toBe($total)
            ->and($request->orderId)->toBe($order)
            ->and($request->state)->toBe(PaymentRequestState::Open)
            ->and(CarbonImmutable::instance($request->expiresAt)->equalTo(CarbonImmutable::now()->addMinutes(60)))->toBeTrue()
            ->and(requestsOutbox(PaymentRequested::class))->toBe(1)
            ->and(DB::table('sales.order_history')->where('order_id', $order)->where('kind', 'PAYMENT_REQUESTED')->count())->toBe(1)
            ->and(DB::table('platform.audit_log')->where('action', 'sales.order.payment_requested')->count())->toBe(1);
    });

    it('asks only the rest of an order partly paid', function () {
        ['order' => $order, 'total' => $total] = requestsReady();
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => 40_00]);

        expect(requestsAsk($order)->amount->minorUnits)->toBe($total - 40_00);
    });

    it('answers the same request while it is open, with nothing told twice', function () {
        ['order' => $order] = requestsReady();

        $first = requestsAsk($order);
        $second = requestsAsk($order);

        expect($second->requestId)->toBe($first->requestId)
            ->and(requestsOutbox(PaymentRequested::class))->toBe(1)
            ->and(DB::table('sales.order_history')->where('kind', 'PAYMENT_REQUESTED')->count())->toBe(1);
    });

    it('withdraws one of another amount first, and asks what is missing now', function () {
        ['order' => $order, 'total' => $total] = requestsReady();
        $first = requestsAsk($order);
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => 40_00]);

        $second = requestsAsk($order);

        expect($second->requestId)->not->toBe($first->requestId)
            ->and($second->amount->minorUnits)->toBe($total - 40_00)
            ->and(requestsState($first->requestId))->toBe('WITHDRAWN');
    });

    it('refuses what cannot be asked', function (Closure $change, string $refusal) {
        ['order' => $order] = requestsReady();
        $change($order);

        expect(fn () => requestsAsk($order))->toThrow($refusal);
    })->with([
        'a company\'s order' => [function (string $order): void {
            DB::table('sales.orders')->where('id', $order)->update(['audience' => 'COMPANY']);
        }, PaymentRequestNotAllowed::class],
        'a fee still to be set' => [function (string $order): void {
            DB::table('sales.orders')->where('id', $order)->update(['shipping_fee_pending' => true, 'shipping_fee_minor' => 0, 'ordered_carrier_id' => null, 'ordered_carrier_ar' => null, 'ordered_carrier_en' => null]);
        }, ShippingFeeNotSet::class],
        'nothing missing' => [function (string $order): void {
            DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
        }, InvalidOrderChange::class],
        'a cancelled order' => [function (string $order): void {
            Cx::asSuperAdmin(static fn () => app(CancelOrderHandler::class)->handle(new CancelOrder($order, 'Out of stock')));
        }, PaymentRequestNotAllowed::class],
        'a gateway switched off' => [function (string $order): void {
            Cx::asSuperAdmin(static fn () => app(SwitchGatewayHandler::class)->handle(new SwitchGateway(Fx::storeId('sa'), false)));
        }, PaymentRequestNotAllowed::class],
        'a gateway in the other mode' => [function (string $order): void {
            Cx::asSuperAdmin(static fn () => app(ChangeKeysHandler::class)->handle(new ChangeKeys(Fx::storeId('sa'), 'TEST', 'sk_key_TEST12', 'whsec_secret_2', '2030-12-31')));
            DB::table('payments.gateways')->update(['mode' => 'TEST']);
        }, PaymentRequestNotAllowed::class],
    ]);

    it('asks a test order\'s request in test mode, and a real order never gets one there', function () {
        ['order' => $order] = requestsReady('TEST');

        expect(requestsAsk($order)->mode->value)->toBe('TEST')
            ->and(DB::table('sales.orders')->where('id', $order)->value('is_test'))->toBeTrue();
    });

    it('is for staff who edit orders, in their stores', function () {
        ['order' => $order] = requestsReady();

        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']));
        expect(fn () => app(RequestOrderPaymentHandler::class)->handle(new RequestOrderPayment($order)))->toThrow(Unauthorized::class)
            ->and(fn () => app(WithdrawOrderRequestHandler::class)->handle(new WithdrawOrderRequest($order)))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_EDIT], ['sa']));
        expect(app(RequestOrderPaymentHandler::class)->handle(new RequestOrderPayment($order))->orderId)->toBe($order);
    });

    it('withdraws by hand, in the history, and with none open does nothing', function () {
        ['order' => $order] = requestsReady();
        $request = requestsAsk($order);

        Cx::asSuperAdmin(static fn () => app(WithdrawOrderRequestHandler::class)->handle(new WithdrawOrderRequest($order)));
        Cx::asSuperAdmin(static fn () => app(WithdrawOrderRequestHandler::class)->handle(new WithdrawOrderRequest($order)));

        expect(requestsState($request->requestId))->toBe('WITHDRAWN')
            ->and(DB::table('sales.order_history')->where('kind', 'PAYMENT_REQUEST_WITHDRAWN')->count())->toBe(1)
            ->and(DB::table('platform.audit_log')->where('action', 'sales.order.payment_request_withdrawn')->count())->toBe(1);
    });
});

describe('the page', function () {
    it('answers only the order\'s own customer: anyone else gets no order', function () {
        ['order' => $order, 'number' => $number] = requestsReady();
        $request = requestsAsk($order);

        Fx::actAsCustomer(Cx::customer());

        expect(fn () => app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number)))->toThrow(OrderNotFound::class)
            ->and(fn () => requestsSession($number))->toThrow(OrderNotFound::class)
            ->and(fn () => requestsPay($number, 'KWT-a-session'))->toThrow(OrderNotFound::class)
            ->and(fn () => requestsFollow($number, $request->requestId))->toThrow(OrderNotFound::class);
    });

    it('takes the order only in its own store', function () {
        ['number' => $number, 'order' => $order] = requestsReady();
        requestsAsk($order);
        $other = Fx::storeId('uae');

        expect(fn () => app(OpenRequestPaymentHandler::class)->handle(new OpenRequestPayment($other, $number, 'en')))->toThrow(OrderNotFound::class)
            ->and(fn () => app(PayOrderRequestHandler::class)->handle(new PayOrderRequest($other, $number, 'KWT-a-session', 'en', 'https://shop.example.test/return')))->toThrow(OrderNotFound::class);
    });

    it('opens a form for the amount asked, lasting the request plus the window and the wait', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake] = requestsReady();
        $request = requestsAsk($order);

        $session = app(OpenRequestPaymentHandler::class)->handle(new OpenRequestPayment(Fx::storeId('sa'), $number, 'en'));

        expect(CarbonImmutable::instance($session->expiresAt)->equalTo(CarbonImmutable::instance($request->expiresAt)->addMinutes(5 + 2)))->toBeTrue()
            ->and($fake->count('POST', '/v3/sessions'))->toBe(1)
            ->and(DB::table('payments.sessions')->where('owner_id', $request->requestId)->value('amount_minor'))->toEqual($request->amount->minorUnits);
    });

    it('says the link has ended with no request open, for the own customer', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake] = requestsReady();

        expect(fn () => requestsSession($number))->toThrow(PaymentLinkEnded::class)
            ->and(fn () => requestsPay($number, 'KWT-a-session'))->toThrow(PaymentLinkEnded::class);

        requestsAsk($order);
        requestsAt('+61 minutes');

        expect(fn () => requestsSession($number))->toThrow(PaymentLinkEnded::class)
            ->and(app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number))->paymentRequest)->toBeNull()
            ->and($fake->count('POST', '/v3/payments'))->toBe(0);
    });

    it('ends the link when the order no longer misses what was asked', function () {
        ['order' => $order, 'number' => $number] = requestsReady();
        requestsAsk($order);
        $session = requestsSession($number);
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => 40_00]);

        expect(fn () => requestsPay($number, $session))->toThrow(PaymentLinkEnded::class)
            ->and(app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number))->paymentRequest)->toBeNull();
    });

    it('takes the session it is given, and refuses none', function () {
        ['order' => $order, 'number' => $number] = requestsReady();
        requestsAsk($order);

        expect(fn () => requestsPay($number, '  '))->toThrow(InvalidSalesAttribute::class)
            ->and(fn () => requestsPay($number, str_repeat('x', 101)))->toThrow(InvalidSalesAttribute::class);
    });

    it('stops abuse at the limits, per order and per customer, changing nothing', function () {
        ['order' => $order, 'number' => $number, 'customer' => $customer] = requestsReady();
        requestsAsk($order);
        $session = requestsSession($number);

        foreach (range(1, PayOrderRequestHandler::MOST_PER_ORDER) as $hit) {
            RateLimiter::hit("sales:pay:order:{$order}", 600);
        }

        expect(fn () => requestsPay($number, $session))->toThrow(TooManyPaymentTries::class);

        RateLimiter::clear("sales:pay:order:{$order}");

        foreach (range(1, 30) as $hit) {
            RateLimiter::hit("sales:pay:customer:{$customer}", 3600);
        }

        expect(fn () => requestsPay($number, $session))->toThrow(TooManyPaymentTries::class)
            ->and(DB::table('payments.attempts')->count())->toBe(0);
    });
});

describe('paying', function () {
    it('charges with no cart and nothing reserved, then records an online payment and tells of it, once', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake, 'total' => $total] = requestsReady();
        $request = requestsAsk($order);
        $holds = DB::table('inventory.holds')->count();
        $cartLines = DB::table('sales.cart_lines')->count();

        $started = requestsPay($number);

        expect($started->requestId)->toBe($request->requestId)
            ->and($started->start->state)->toBe(PaymentState::InProgress)
            ->and($fake->count('POST', '/v3/payments'))->toBe(1)
            ->and(DB::table('inventory.holds')->count())->toBe($holds)
            ->and(DB::table('sales.cart_lines')->count())->toBe($cartLines)
            ->and(DB::table('sales.checkout_tries')->count())->toBe(0)
            ->and((int) DB::table('payments.attempts')->where('owner_id', $request->requestId)->value('amount_minor'))->toBe($total)
            ->and(DB::table('payments.attempts')->where('owner_id', $request->requestId)->value('order_id'))->toBe($order);

        $fake->paid(requestsAmount($total));
        $view = requestsFollow($number, $request->requestId);

        expect($view->payment)->toBe(PaymentState::Succeeded)
            ->and($view->requestId)->toBe($request->requestId)
            ->and(requestsOnline($order))->toBe(1)
            ->and((int) DB::table('sales.orders')->where('id', $order)->value('paid_minor'))->toBe($total)
            ->and(requestsOutbox(PaymentRecorded::class))->toBe(1)
            ->and(requestsState($request->requestId))->toBe('PAID');

        // The event after the page, and the page again: the same payment.
        app(RecordRequestPayment::class)->handle(requestsSucceeded());
        requestsFollow($number, $request->requestId);

        expect(requestsOnline($order))->toBe(1)
            ->and(requestsOutbox(PaymentRecorded::class))->toBe(1)
            ->and(app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number))->paymentRequest)->toBeNull();
    });

    it('records from the event when the page never comes back, once per event', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake, 'total' => $total] = requestsReady();
        $request = requestsAsk($order);
        requestsPay($number);
        $fake->paid(requestsAmount($total));
        app(PaymentsApi::class)->refresh($request->requestId);
        $event = requestsSucceeded();

        app(RecordRequestPayment::class)->handle($event);
        app(RecordRequestPayment::class)->handle($event);

        expect(requestsOnline($order))->toBe(1)
            ->and(requestsOutbox(PaymentRecorded::class))->toBe(1);
    });

    it('stays open after a decline, and the customer may try again while its time lasts', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake, 'total' => $total] = requestsReady();
        $request = requestsAsk($order);
        requestsPay($number);
        $fake->declined();

        $view = requestsFollow($number, $request->requestId);

        expect($view->payment)->toBe(PaymentState::Failed)
            ->and($view->declineCode)->toBe('MF003')
            ->and(requestsOnline($order))->toBe(0)
            ->and(requestsState($request->requestId))->toBe('OPEN')
            ->and(app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number))->paymentRequest?->requestId)->toBe($request->requestId);

        [$fake->status, $fake->invoiceStatus, $fake->errorCode, $fake->invoiceId] = ['INPROGRESS', 'PENDING', '', '7320291'];
        requestsPay($number);
        $fake->paid(requestsAmount($total));

        expect(requestsFollow($number, $request->requestId)->payment)->toBe(PaymentState::Succeeded)
            ->and(requestsOnline($order))->toBe(1);
    });

    it('shows a second window that a payment is in progress, with no second charge', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake] = requestsReady();
        requestsAsk($order);
        $session = requestsSession($number);
        requestsPay($number, $session);

        expect(fn () => requestsSession($number))->toThrow(PaymentInProgress::class)
            ->and(fn () => requestsPay($number, $session))->toThrow(DomainError::class)
            ->and($fake->count('POST', '/v3/payments'))->toBe(1);
    });

    it('does not expire while a payment on it is open', function () {
        ['order' => $order, 'number' => $number] = requestsReady();
        $request = requestsAsk($order);
        requestsAt('+58 minutes');
        requestsPay($number);
        requestsAt('+61 minutes');

        expect(requestsState($request->requestId))->toBe('OPEN')
            ->and(app(PaymentsApi::class)->openRequest($order)?->requestId)->toBe($request->requestId);
    });

    it('follows only a request of this order', function () {
        ['order' => $order, 'number' => $number] = requestsReady();
        requestsAsk($order);
        requestsPay($number);

        expect(fn () => requestsFollow($number, strtolower((string) Str::ulid())))->toThrow(OrderNotFound::class);
    });

    it('marks test money by the payment', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake, 'total' => $total] = requestsReady('TEST');
        $request = requestsAsk($order);
        requestsPay($number);
        $fake->paid(requestsAmount($total));
        requestsFollow($number, $request->requestId);

        expect(DB::table('sales.payments')->where('order_id', $order)->value('is_test'))->toBeTrue();
    });

    it('records a second success as a second payment, and never refuses one on a cancelled order', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake, 'total' => $total] = requestsReady();
        $request = requestsAsk($order);
        requestsPay($number);
        $fake->paid(requestsAmount($total));
        requestsFollow($number, $request->requestId);
        $first = (array) DB::table('payments.transactions')->sole();
        unset($first['id']);
        DB::table('payments.transactions')->insert([...$first, 'gateway_payment_id' => 'second-payment']);
        $second = (string) DB::table('payments.transactions')->where('gateway_payment_id', 'second-payment')->value('id');
        Cx::asSuperAdmin(static fn () => app(CancelOrderHandler::class)->handle(new CancelOrder($order, 'Out of stock')));

        app(RecordRequestPayment::class)->handle(new PaymentSucceeded((string) Str::uuid(), $second, $order, Fx::storeId('sa'), CarbonImmutable::now()));

        expect(requestsOnline($order))->toBe(2)
            ->and(requestsOutbox(PaymentRecorded::class))->toBe(2)
            ->and((int) DB::table('sales.orders')->where('id', $order)->value('paid_minor'))->toBe($total * 2);
    });

    it('leaves a try\'s order to the try', function () {
        requestsReady();
        $tried = strtolower((string) Str::ulid());
        DB::table('sales.checkout_tries')->insert([
            'id' => strtolower((string) Str::ulid()), 'store_id' => Fx::storeId('sa'), 'customer_id' => Cx::customer(), 'cart_id' => strtolower((string) Str::ulid()), 'cart_version' => 0,
            'quote_id' => strtolower((string) Str::ulid()), 'order_id' => $tried, 'attempt_id' => strtolower((string) Str::ulid()), 'session_id' => 'KWT-x', 'state' => 'LIVE',
            'amount_minor' => 1, 'currency_code' => 'SAR', 'window_ends_at' => CarbonImmutable::now(), 'created_at' => CarbonImmutable::now(),
        ]);

        app(RecordRequestPayment::class)->handle(new PaymentSucceeded((string) Str::uuid(), 'not-looked-at', $tried, Fx::storeId('sa'), CarbonImmutable::now()));

        expect(DB::table('sales.payments')->count())->toBe(0)
            ->and(DB::table('public.processed_events')->count())->toBe(0);
    });
});

describe('waiting for a payment in progress', function () {
    it('makes staff edits, setting the fee and hand recording wait, and the customer\'s cancel, but not staff\'s', function () {
        ['order' => $order, 'number' => $number, 'variants' => $variants] = requestsReady();
        requestsAsk($order);
        requestsPay($number);

        expect(fn () => requestsEdit($order, $variants, 3))->toThrow(PaymentBeingConfirmed::class)
            ->and(fn () => Cx::asSuperAdmin(static fn () => app(SetShippingFeeHandler::class)->handle(new SetShippingFee($order, 5_00, 'Agreed'))))->toThrow(PaymentBeingConfirmed::class)
            ->and(fn () => requestsRecordByHand($order))->toThrow(PaymentBeingConfirmed::class)
            ->and(fn () => Cx::asSuperAdmin(static fn () => app(WithdrawOrderRequestHandler::class)->handle(new WithdrawOrderRequest($order))))->toThrow(PaymentBeingConfirmed::class)
            ->and(fn () => app(CancelOwnOrderHandler::class)->handle(new CancelOwnOrder($order, 'CHANGED_MIND')))->toThrow(PaymentBeingConfirmed::class);

        $own = app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number));
        expect($own->paymentBeingConfirmed)->toBeTrue()
            ->and($own->mayCancel)->toBeFalse()
            ->and(requestsStaffView($order)->paymentInProgress)->toBeTrue()
            ->and(requestsStaffView($order)->mayRequestPayment)->toBeFalse();

        Cx::asSuperAdmin(static fn () => app(CancelOrderHandler::class)->handle(new CancelOrder($order, 'Out of stock')));
        expect(DB::table('sales.orders')->where('id', $order)->value('status'))->toBe('CANCELLED');
    });

    it('refuses a new request while a payment on the order is open', function () {
        ['order' => $order, 'number' => $number] = requestsReady();
        requestsAsk($order);
        requestsPay($number);

        expect(fn () => requestsAsk($order))->toThrow(PaymentBeingConfirmed::class);
    });

    it('lets a refund through: only payments wait', function () {
        ['order' => $order, 'number' => $number] = requestsReady();
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => 40_00]);
        requestsAsk($order);
        requestsPay($number);

        Cx::asSuperAdmin(static fn () => app(RecordRefundHandler::class)->handle(new RecordRefund($order, '10.00', 'OTHER', null, 'Cash', '2027-01-01', (string) Str::uuid())));

        expect((int) DB::table('sales.orders')->where('id', $order)->value('refunded_minor'))->toBe(10_00);
    });

    it('frees the customer\'s cancel once the payment is answered', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake] = requestsReady();
        $request = requestsAsk($order);
        requestsPay($number);
        $fake->declined();
        requestsFollow($number, $request->requestId);

        app(CancelOwnOrderHandler::class)->handle(new CancelOwnOrder($order, 'CHANGED_MIND'));

        expect(DB::table('sales.orders')->where('id', $order)->value('status'))->toBe('CANCELLED');
    });
});

describe('ending a request', function () {
    it('withdraws the open request when a payment is recorded by hand', function () {
        ['order' => $order] = requestsReady();
        $request = requestsAsk($order);

        requestsRecordByHand($order);

        expect(requestsState($request->requestId))->toBe('WITHDRAWN')
            ->and(DB::table('sales.payments')->where('order_id', $order)->count())->toBe(1);
    });

    it('leaves it open when the same form of an earlier payment is sent again', function () {
        ['order' => $order] = requestsReady();
        $form = (string) Str::uuid();
        Cx::asSuperAdmin(static fn () => app(RecordPaymentHandler::class)->handle(new RecordPayment($order, 'OTHER', null, 'Cash', '2027-01-01', $form)));
        DB::table('sales.orders')->where('id', $order)->update(['due_minor' => DB::raw('due_minor + 100')]);
        $request = requestsAsk($order);

        Cx::asSuperAdmin(static fn () => app(RecordPaymentHandler::class)->handle(new RecordPayment($order, 'OTHER', null, 'Cash', '2027-01-01', $form)));

        expect(requestsState($request->requestId))->toBe('OPEN');
    });

    it('withdraws it when an edit changes the amount, and keeps it when it does not', function () {
        ['order' => $order, 'variants' => $variants] = requestsReady();
        $request = requestsAsk($order);

        requestsEdit($order, $variants, 2);
        expect(requestsState($request->requestId))->toBe('OPEN');

        requestsEdit($order, $variants, 3);
        expect(requestsState($request->requestId))->toBe('WITHDRAWN');
    });

    it('withdraws it when setting the fee changes the amount, and keeps it when it does not', function () {
        ['order' => $order] = requestsReady();
        DB::table('sales.orders')->where('id', $order)->update(['ordered_carrier_id' => null, 'ordered_carrier_ar' => null, 'ordered_carrier_en' => null, 'shipping_fee_minor' => 0, 'shipping_fee_pending' => false]);
        $request = requestsAsk($order);

        Cx::asSuperAdmin(static fn () => app(SetShippingFeeHandler::class)->handle(new SetShippingFee($order, 0, 'The same')));
        expect(requestsState($request->requestId))->toBe('OPEN');

        Cx::asSuperAdmin(static fn () => app(SetShippingFeeHandler::class)->handle(new SetShippingFee($order, 5_00, 'Agreed')));
        expect(requestsState($request->requestId))->toBe('WITHDRAWN');
    });

    it('tells Payments when the order is cancelled, whose listener withdraws it', function () {
        ['order' => $order] = requestsReady();
        requestsAsk($order);

        app(CancelOwnOrderHandler::class)->handle(new CancelOwnOrder($order, 'CHANGED_MIND'));

        expect(DB::table('public.outbox_messages')->where('type', 'like', '%OrderCancelled')->count())->toBe(1);
    });
});

describe('what the screens read', function () {
    it('shows the customer the request on their order, and on their list in one read', function () {
        ['order' => $order, 'number' => $number, 'total' => $total] = requestsReady();
        $request = requestsAsk($order);

        $view = app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number));

        expect($view->paymentRequest?->requestId)->toBe($request->requestId)
            ->and($view->paymentRequest?->amount->minorUnits)->toBe($total)
            ->and($view->paymentBeingConfirmed)->toBeFalse()
            ->and($view->mayCancel)->toBeTrue();

        DB::enableQueryLog();
        $rows = app(ListOwnOrdersHandler::class)->handle(new ListOwnOrders)->orders;
        $reads = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains((string) $query['query'], 'requests'));
        DB::disableQueryLog();

        expect($rows[0]->paymentRequested)->toBeTrue()
            ->and(count($reads))->toBe(1);
    });

    it('shows no request on a list row whose amount no longer matches, and none when there is none', function () {
        ['order' => $order] = requestsReady();

        expect(app(ListOwnOrdersHandler::class)->handle(new ListOwnOrders)->orders[0]->paymentRequested)->toBeFalse();

        requestsAsk($order);
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => 40_00]);

        expect(app(ListOwnOrdersHandler::class)->handle(new ListOwnOrders)->orders[0]->paymentRequested)->toBeFalse();
    });

    it('asks Payments nothing for a page with nothing missing', function () {
        ['order' => $order] = requestsReady();
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);

        DB::enableQueryLog();
        app(ListOwnOrdersHandler::class)->handle(new ListOwnOrders);
        $reads = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains((string) $query['query'], 'requests'));
        DB::disableQueryLog();

        expect($reads)->toBe([]);
    });

    it('offers staff Request Payment while the order owes and nothing is in the way', function () {
        ['order' => $order] = requestsReady();

        expect(requestsStaffView($order)->mayRequestPayment)->toBeTrue()
            ->and(requestsStaffView($order)->paymentRequest)->toBeNull();

        $request = requestsAsk($order);
        $view = requestsStaffView($order);

        expect($view->mayRequestPayment)->toBeFalse()
            ->and($view->paymentRequest?->requestId)->toBe($request->requestId)
            ->and($view->paymentInProgress)->toBeFalse();
    });

    it('does not offer it for what the command would refuse', function (Closure $change) {
        ['order' => $order] = requestsReady();
        $change($order);

        expect(requestsStaffView($order)->mayRequestPayment)->toBeFalse();
    })->with([
        'a company\'s order' => [function (string $order): void {
            DB::table('sales.orders')->where('id', $order)->update(['audience' => 'COMPANY']);
        }],
        'a fee still to be set' => [function (string $order): void {
            DB::table('sales.orders')->where('id', $order)->update(['shipping_fee_pending' => true, 'shipping_fee_minor' => 0, 'ordered_carrier_id' => null, 'ordered_carrier_ar' => null, 'ordered_carrier_en' => null]);
        }],
        'nothing missing' => [function (string $order): void {
            DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
        }],
        'a gateway off' => [function (string $order): void {
            Cx::asSuperAdmin(static fn () => app(SwitchGatewayHandler::class)->handle(new SwitchGateway(Fx::storeId('sa'), false)));
        }],
        'a gateway in the other mode' => [function (string $order): void {
            Cx::asSuperAdmin(static fn () => app(ChangeKeysHandler::class)->handle(new ChangeKeys(Fx::storeId('sa'), 'TEST', 'sk_key_TEST12', 'whsec_secret_2', '2030-12-31')));
            DB::table('payments.gateways')->update(['mode' => 'TEST']);
        }],
    ]);

    it('lists the attempts only for staff who may read payments', function () {
        ['order' => $order, 'number' => $number] = requestsReady();
        $request = requestsAsk($order);
        requestsPay($number);

        $attempts = requestsStaffView($order)->attempts ?? [];
        expect($attempts)->toHaveCount(1)
            ->and($attempts[0]->ownerId ?? null)->toBe($request->requestId);

        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']));
        expect(app(ViewOrderHandler::class)->handle(new ViewOrder($order))->attempts)->toBeNull();

        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW, ViewOrderHandler::ATTEMPTS_PERMISSION], ['sa']));
        expect(app(ViewOrderHandler::class)->handle(new ViewOrder($order))->attempts)->toHaveCount(1);
    });

    it('keeps staff\'s online payment rows and offers nothing once it is paid', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake, 'total' => $total] = requestsReady();
        $request = requestsAsk($order);
        requestsPay($number);
        $fake->paid(requestsAmount($total));
        requestsFollow($number, $request->requestId);

        $view = requestsStaffView($order);

        expect($view->paymentRequest)->toBeNull()
            ->and($view->paymentInProgress)->toBeFalse()
            ->and($view->mayRequestPayment)->toBeFalse()
            ->and($view->attempts)->toHaveCount(1);
    });
});

/** The order turned into a bank transfer's, its receipt uploaded now by its customer: only the columns matter here. */
function requestsTransfer(string $order): void
{
    DB::table('sales.orders')->where('id', $order)->update(['way_to_pay' => 'BANK_TRANSFER']);
    app(UploadTransferDocumentHandler::class)->handle(new UploadTransferDocument($order, Bx::pdf(), 'transfer.pdf'));
}

/** @return list<StaffOrderRow> */
function requestsOverdueRows(): array
{
    return Cx::asSuperAdmin(static fn (): array => app(ListOrdersHandler::class)->handle(new ListOrders)->orders);
}

describe('a transfer past its check time', function () {
    it('is told once per order after the store\'s hours, and marked for staff', function () {
        ['order' => $order] = requestsReady();
        requestsTransfer($order);

        requestsAt('+71 hours');
        app(TransferOverdueCheck::class)->run();
        expect(requestsOutbox(TransferOverdue::class))->toBe(0)
            ->and(requestsOverdueRows()[0]->isOverdue)->toBeFalse()
            ->and(requestsStaffView($order)->isOverdue)->toBeFalse();

        requestsAt('+72 hours');
        app(TransferOverdueCheck::class)->run();
        app(TransferOverdueCheck::class)->run();

        $payload = json_decode((string) DB::table('public.outbox_messages')->where('type', TransferOverdue::class)->value('payload'), true);
        expect(requestsOutbox(TransferOverdue::class))->toBe(1)
            ->and($payload)->toMatchArray(['orderId' => $order, 'storeId' => Fx::storeId('sa')])
            ->and(DB::table('sales.orders')->where('id', $order)->value('overdue_told_at'))->not->toBeNull()
            ->and(requestsOverdueRows()[0]->isOverdue)->toBeTrue()
            ->and(requestsStaffView($order)->isOverdue)->toBeTrue();
    });

    it('counts from the first receipt, by the store\'s own setting', function () {
        ['order' => $order] = requestsReady();
        requestsTransfer($order);
        requestsAt('+12 hours');
        app(UploadTransferDocumentHandler::class)->handle(new UploadTransferDocument($order, Bx::pdf(), 'second.pdf'));
        Cx::setting('payments.transfer.check_hours', 10);

        app(TransferOverdueCheck::class)->run();

        expect(requestsOutbox(TransferOverdue::class))->toBe(1);
    });

    it('leaves out what is paid, a test order, another way to pay and an order with no receipt', function (Closure $change) {
        ['order' => $order] = requestsReady();
        requestsTransfer($order);
        $change($order);
        requestsAt('+73 hours');

        app(TransferOverdueCheck::class)->run();

        expect(requestsOutbox(TransferOverdue::class))->toBe(0)
            ->and(requestsOverdueRows()[0]->isOverdue)->toBeFalse()
            ->and(requestsStaffView($order)->isOverdue)->toBeFalse();
    })->with([
        'paid' => [function (string $order): void {
            DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
        }],
        'a test order' => [function (string $order): void {
            DB::table('sales.orders')->where('id', $order)->update(['is_test' => true]);
        }],
        'another way' => [function (string $order): void {
            DB::table('sales.orders')->where('id', $order)->update(['way_to_pay' => 'STAFF_CONTACT']);
        }],
        'no receipt' => [function (string $order): void {
            DB::table('sales.transfer_documents')->where('order_id', $order)->delete();
        }],
    ]);

    it('looks only in the stores holding one', function () {
        ['order' => $order] = requestsReady();

        expect(app(Orders::class)->storesWithUncheckedTransfers())->toBe([]);

        requestsTransfer($order);

        expect(app(Orders::class)->storesWithUncheckedTransfers())->toBe([Fx::storeId('sa')]);
    });
});

it('wakes nothing on an order with no request, for the helper the screens lean on', function () {
    ['order' => $order] = requestsReady();

    expect(app(OrderRequests::class)->live($order, 0))->toBeNull()
        ->and(app(OrderRequests::class)->live($order, 1))->toBeNull();
});
