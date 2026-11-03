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
use Modules\Sales\Infrastructure\Listener\WithdrawRequestWhenCancelled;
use Modules\Sales\Public\Events\OrderCancelled;
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

const ORDER_PAY_NOW = '2027-01-01T12:00:00Z';

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    app()->instance(KeyTester::class, new class implements KeyTester
    {
        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void {}
    });
    Sleep::fake();
    orderPayAt('+0 minutes');
    RateLimiter::clear('payments-checks');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
    File::deleteDirectory(Bx::uploads());
});

function orderPayAt(string $when): void
{
    $moment = CarbonImmutable::parse(ORDER_PAY_NOW)->modify($when);
    CarbonImmutable::setTestNow($moment);
    Carbon::setTestNow($moment);
}

/**
 * The store's gateway on, and an individual's order placed through staff: acting as the customer.
 *
 * @return array{fake: FakeMyFatoorah, order: string, number: string, total: int, customer: string, variants: list<string>, address: string}
 */
function orderPayReady(string $mode = 'LIVE'): array
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

function orderPayAsk(string $order): PaymentRequestStateDto
{
    return Cx::asSuperAdmin(static fn (): PaymentRequestStateDto => app(RequestOrderPaymentHandler::class)->handle(new RequestOrderPayment($order)));
}

function orderPaySession(string $number): string
{
    return app(OpenRequestPaymentHandler::class)->handle(new OpenRequestPayment(Fx::storeId('sa'), $number, 'en'))->sessionId;
}

function orderPayPay(string $number, ?string $session = null): RequestStart
{
    return app(PayOrderRequestHandler::class)->handle(new PayOrderRequest(Fx::storeId('sa'), $number, $session ?? orderPaySession($number), 'en', 'https://shop.example.test/return'));
}

function orderPayFollow(string $number, string $requestId): RequestPaymentView
{
    return app(FollowRequestPaymentHandler::class)->handle(new FollowRequestPayment(Fx::storeId('sa'), $number, $requestId));
}

function orderPayAmount(int $minor): string
{
    return number_format($minor / 100, 2, '.', '');
}

function orderPayOutbox(string $type): int
{
    return DB::table('public.outbox_messages')->where('type', $type)->count();
}

function orderPayState(string $requestId): string
{
    return (string) DB::table('payments.requests')->where('id', $requestId)->value('state');
}

/** The money recorded online on the order. */
function orderPayOnline(string $order): int
{
    return DB::table('sales.payments')->where('order_id', $order)->where('way', 'ONLINE')->count();
}

/** The latest `PaymentSucceeded` as it went through the outbox. */
function orderPaySucceeded(): PaymentSucceeded
{
    $row = DB::table('public.outbox_messages')->where('type', PaymentSucceeded::class)->orderByDesc('id')->first() ?? throw new LogicException('No such event.');
    /** @var array{eventId: string, paymentId: string, orderId: string, storeId: string, occurredAt: string} $payload */
    $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);

    return new PaymentSucceeded($payload['eventId'], $payload['paymentId'], $payload['orderId'], $payload['storeId'], CarbonImmutable::parse($payload['occurredAt']));
}

/** @param  list<string>  $variants */
function orderPayEdit(string $order, array $variants, int $firstQuantity): string
{
    return Cx::asSuperAdmin(static fn (): string => app(EditOrderHandler::class)->handle(new EditOrder($order, 'Called the customer', [
        ['variantId' => $variants[0], 'mode' => 'RETAIL', 'quantity' => $firstQuantity],
        ['variantId' => $variants[1], 'mode' => 'RETAIL', 'quantity' => 1],
    ], null, 'KEEP')));
}

function orderPayRecordByHand(string $order): string
{
    return Cx::asSuperAdmin(static fn (): string => app(RecordPaymentHandler::class)->handle(new RecordPayment($order, 'OTHER', null, 'Cash', '2027-01-01', (string) Str::uuid())));
}

function orderPayStaffView(string $order): StaffOrderView
{
    return Cx::asSuperAdmin(static fn (): StaffOrderView => app(ViewOrderHandler::class)->handle(new ViewOrder($order)));
}

describe('asking', function () {
    it('asks exactly what is missing, for 60 minutes, told once, in the history', function () {
        ['order' => $order, 'total' => $total] = orderPayReady();

        $request = orderPayAsk($order);

        expect($request->amount->minorUnits)->toBe($total)
            ->and($request->orderId)->toBe($order)
            ->and($request->state)->toBe(PaymentRequestState::Open)
            ->and(CarbonImmutable::instance($request->expiresAt)->equalTo(CarbonImmutable::now()->addMinutes(60)))->toBeTrue()
            ->and(orderPayOutbox(PaymentRequested::class))->toBe(1)
            ->and(DB::table('sales.order_history')->where('order_id', $order)->where('kind', 'PAYMENT_REQUESTED')->count())->toBe(1)
            ->and(DB::table('platform.audit_entries')->where('action', 'sales.order.payment_requested')->count())->toBe(1);
    });

    it('asks only the rest of an order partly paid', function () {
        ['order' => $order, 'total' => $total] = orderPayReady();
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => 40_00]);

        expect(orderPayAsk($order)->amount->minorUnits)->toBe($total - 40_00);
    });

    it('answers the same request while it is open, with nothing told twice', function () {
        ['order' => $order] = orderPayReady();

        $first = orderPayAsk($order);
        $second = orderPayAsk($order);

        expect($second->requestId)->toBe($first->requestId)
            ->and(orderPayOutbox(PaymentRequested::class))->toBe(1)
            ->and(DB::table('sales.order_history')->where('kind', 'PAYMENT_REQUESTED')->count())->toBe(1);
    });

    it('withdraws one of another amount first, and asks what is missing now', function () {
        ['order' => $order, 'total' => $total] = orderPayReady();
        $first = orderPayAsk($order);
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => 40_00]);

        $second = orderPayAsk($order);

        expect($second->requestId)->not->toBe($first->requestId)
            ->and($second->amount->minorUnits)->toBe($total - 40_00)
            ->and(orderPayState($first->requestId))->toBe('WITHDRAWN');
    });

    it('refuses what cannot be asked', function (Closure $change, string $refusal) {
        ['order' => $order] = orderPayReady();
        $change($order);

        expect(fn () => orderPayAsk($order))->toThrow($refusal);
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
        'a key the gateway refused' => [function (string $order): void {
            DB::table('payments.gateway_keys')->update(['rejected_at' => CarbonImmutable::now()]);
        }, PaymentRequestNotAllowed::class],
    ]);

    it('asks a test order\'s request in test mode, and a real order never gets one there', function () {
        ['order' => $order] = orderPayReady('TEST');

        expect(orderPayAsk($order)->mode->value)->toBe('TEST')
            ->and(DB::table('sales.orders')->where('id', $order)->value('is_test'))->toBeTrue();
    });

    it('is for staff who edit orders, in their stores', function () {
        ['order' => $order] = orderPayReady();

        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']));
        expect(fn () => app(RequestOrderPaymentHandler::class)->handle(new RequestOrderPayment($order)))->toThrow(Unauthorized::class)
            ->and(fn () => app(WithdrawOrderRequestHandler::class)->handle(new WithdrawOrderRequest($order)))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_EDIT], ['sa']));
        expect(app(RequestOrderPaymentHandler::class)->handle(new RequestOrderPayment($order))->orderId)->toBe($order);
    });

    it('withdraws by hand, in the history, and with none open does nothing', function () {
        ['order' => $order] = orderPayReady();
        $request = orderPayAsk($order);

        Cx::asSuperAdmin(static fn () => app(WithdrawOrderRequestHandler::class)->handle(new WithdrawOrderRequest($order)));
        Cx::asSuperAdmin(static fn () => app(WithdrawOrderRequestHandler::class)->handle(new WithdrawOrderRequest($order)));

        expect(orderPayState($request->requestId))->toBe('WITHDRAWN')
            ->and(DB::table('sales.order_history')->where('kind', 'PAYMENT_REQUEST_WITHDRAWN')->count())->toBe(1)
            ->and(DB::table('platform.audit_entries')->where('action', 'sales.order.payment_request_withdrawn')->count())->toBe(1);
    });
});

describe('the page', function () {
    it('answers only the order\'s own customer: anyone else gets no order', function () {
        ['order' => $order, 'number' => $number] = orderPayReady();
        $request = orderPayAsk($order);

        Fx::actAsCustomer(Cx::customer());

        expect(fn () => app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number)))->toThrow(OrderNotFound::class)
            ->and(fn () => orderPaySession($number))->toThrow(OrderNotFound::class)
            ->and(fn () => orderPayPay($number, 'KWT-a-session'))->toThrow(OrderNotFound::class)
            ->and(fn () => orderPayFollow($number, $request->requestId))->toThrow(OrderNotFound::class);
    });

    it('is for a signed-in customer, never staff', function () {
        ['order' => $order, 'number' => $number] = orderPayReady();
        $request = orderPayAsk($order);

        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => orderPaySession($number))->toThrow(Unauthorized::class)
            ->and(fn () => orderPayPay($number, 'KWT-a-session'))->toThrow(Unauthorized::class)
            ->and(fn () => orderPayFollow($number, $request->requestId))->toThrow(Unauthorized::class);
    });

    it('takes the order only in its own store', function () {
        ['number' => $number, 'order' => $order] = orderPayReady();
        orderPayAsk($order);
        $other = Fx::storeId('eg');

        expect(fn () => app(OpenRequestPaymentHandler::class)->handle(new OpenRequestPayment($other, $number, 'en')))->toThrow(OrderNotFound::class)
            ->and(fn () => app(PayOrderRequestHandler::class)->handle(new PayOrderRequest($other, $number, 'KWT-a-session', 'en', 'https://shop.example.test/return')))->toThrow(OrderNotFound::class);
    });

    it('opens a form for the amount asked, lasting the request plus the window and the wait', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake] = orderPayReady();
        $request = orderPayAsk($order);

        $session = app(OpenRequestPaymentHandler::class)->handle(new OpenRequestPayment(Fx::storeId('sa'), $number, 'en'));

        expect(CarbonImmutable::instance($session->expiresAt)->equalTo(CarbonImmutable::instance($request->expiresAt)->addMinutes(5 + 2)))->toBeTrue()
            ->and($fake->count('POST', '/v3/sessions'))->toBe(1)
            ->and(DB::table('payments.sessions')->where('owner_id', $request->requestId)->value('amount_minor'))->toEqual($request->amount->minorUnits);
    });

    it('says the link has ended with no request open, for the own customer', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake] = orderPayReady();

        expect(fn () => orderPaySession($number))->toThrow(PaymentLinkEnded::class)
            ->and(fn () => orderPayPay($number, 'KWT-a-session'))->toThrow(PaymentLinkEnded::class);

        orderPayAsk($order);
        orderPayAt('+61 minutes');

        expect(fn () => orderPaySession($number))->toThrow(PaymentLinkEnded::class)
            ->and(app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number))->paymentRequest)->toBeNull()
            ->and($fake->count('POST', '/v3/payments'))->toBe(0);
    });

    it('ends the link when the order no longer misses what was asked', function () {
        ['order' => $order, 'number' => $number] = orderPayReady();
        orderPayAsk($order);
        $session = orderPaySession($number);
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => 40_00]);

        expect(fn () => orderPayPay($number, $session))->toThrow(PaymentLinkEnded::class)
            ->and(app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number))->paymentRequest)->toBeNull();
    });

    it('takes the session it is given, and refuses none', function () {
        ['order' => $order, 'number' => $number] = orderPayReady();
        orderPayAsk($order);

        expect(fn () => orderPayPay($number, '  '))->toThrow(InvalidSalesAttribute::class)
            ->and(fn () => orderPayPay($number, str_repeat('x', 101)))->toThrow(InvalidSalesAttribute::class);
    });

    it('stops abuse at the limits, per order and per customer, changing nothing', function () {
        ['order' => $order, 'number' => $number, 'customer' => $customer] = orderPayReady();
        orderPayAsk($order);
        $session = orderPaySession($number);

        foreach (range(1, PayOrderRequestHandler::MOST_PER_ORDER) as $hit) {
            RateLimiter::hit("sales:pay:order:{$order}", 600);
        }

        expect(fn () => orderPayPay($number, $session))->toThrow(TooManyPaymentTries::class);

        RateLimiter::clear("sales:pay:order:{$order}");

        foreach (range(1, 30) as $hit) {
            RateLimiter::hit("sales:pay:customer:{$customer}", 3600);
        }

        expect(fn () => orderPayPay($number, $session))->toThrow(TooManyPaymentTries::class)
            ->and(DB::table('payments.attempts')->count())->toBe(0);
    });
});

describe('paying', function () {
    it('charges with no cart and nothing reserved, then records an online payment and tells of it, once', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake, 'total' => $total] = orderPayReady();
        $request = orderPayAsk($order);
        $holds = DB::table('inventory.holds')->count();
        $cartLines = DB::table('sales.cart_lines')->count();

        $started = orderPayPay($number);

        expect($started->requestId)->toBe($request->requestId)
            ->and($started->start->state)->toBe(PaymentState::InProgress)
            ->and($fake->count('POST', '/v3/payments'))->toBe(1)
            ->and(DB::table('inventory.holds')->count())->toBe($holds)
            ->and(DB::table('sales.cart_lines')->count())->toBe($cartLines)
            ->and(DB::table('sales.checkout_tries')->count())->toBe(0)
            ->and((int) DB::table('payments.attempts')->where('owner_id', $request->requestId)->value('amount_minor'))->toBe($total)
            ->and(DB::table('payments.attempts')->where('owner_id', $request->requestId)->value('order_id'))->toBe($order);

        $fake->paid(orderPayAmount($total));
        $view = orderPayFollow($number, $request->requestId);

        expect($view->payment)->toBe(PaymentState::Succeeded)
            ->and($view->requestId)->toBe($request->requestId)
            ->and(orderPayOnline($order))->toBe(1)
            ->and((int) DB::table('sales.orders')->where('id', $order)->value('paid_minor'))->toBe($total)
            ->and(orderPayOutbox(PaymentRecorded::class))->toBe(1)
            ->and(orderPayState($request->requestId))->toBe('PAID');

        // The event after the page, and the page again: the same payment.
        app(RecordRequestPayment::class)->handle(orderPaySucceeded());
        orderPayFollow($number, $request->requestId);

        expect(orderPayOnline($order))->toBe(1)
            ->and(orderPayOutbox(PaymentRecorded::class))->toBe(1)
            ->and(app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number))->paymentRequest)->toBeNull();
    });

    it('records from the event when the page never comes back, once per event', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake, 'total' => $total] = orderPayReady();
        $request = orderPayAsk($order);
        orderPayPay($number);
        $fake->paid(orderPayAmount($total));
        app(PaymentsApi::class)->refresh($request->requestId);
        $event = orderPaySucceeded();

        app(RecordRequestPayment::class)->handle($event);
        app(RecordRequestPayment::class)->handle($event);

        expect(orderPayOnline($order))->toBe(1)
            ->and(orderPayOutbox(PaymentRecorded::class))->toBe(1);
    });

    it('stays open after a decline, and the customer may try again while its time lasts', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake, 'total' => $total] = orderPayReady();
        $request = orderPayAsk($order);
        orderPayPay($number);
        $fake->declined();

        $view = orderPayFollow($number, $request->requestId);

        expect($view->payment)->toBe(PaymentState::Failed)
            ->and($view->declineCode)->toBe('MF003')
            ->and(orderPayOnline($order))->toBe(0)
            ->and(orderPayState($request->requestId))->toBe('OPEN')
            ->and(app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number))->paymentRequest?->requestId)->toBe($request->requestId);

        [$fake->status, $fake->invoiceStatus, $fake->errorCode, $fake->invoiceId] = ['INPROGRESS', 'PENDING', '', '7320291'];
        orderPayPay($number);
        $fake->paid(orderPayAmount($total));

        expect(orderPayFollow($number, $request->requestId)->payment)->toBe(PaymentState::Succeeded)
            ->and(orderPayOnline($order))->toBe(1);
    });

    it('answers a double click with the same attempt, and shows a second window that a payment is in progress', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake] = orderPayReady();
        $request = orderPayAsk($order);
        $session = orderPaySession($number);
        $first = orderPayPay($number, $session);

        $again = orderPayPay($number, $session);

        expect($again->requestId)->toBe($request->requestId)
            ->and($again->start->attemptId)->toBe($first->start->attemptId)
            ->and(fn () => orderPaySession($number))->toThrow(PaymentInProgress::class)
            ->and(fn () => orderPayPay($number, 'KWT-another-window'))->toThrow(DomainError::class)
            ->and(DB::table('payments.attempts')->count())->toBe(1)
            ->and($fake->count('POST', '/v3/payments'))->toBe(1);
    });

    it('does not expire while a payment on it is open', function () {
        ['order' => $order, 'number' => $number] = orderPayReady();
        $request = orderPayAsk($order);
        orderPayAt('+58 minutes');
        orderPayPay($number);
        orderPayAt('+61 minutes');

        expect(orderPayState($request->requestId))->toBe('OPEN')
            ->and(app(PaymentsApi::class)->openRequest($order)?->requestId)->toBe($request->requestId);
    });

    it('follows only a request of this order', function () {
        ['order' => $order, 'number' => $number] = orderPayReady();
        orderPayAsk($order);
        orderPayPay($number);

        expect(fn () => orderPayFollow($number, strtolower((string) Str::ulid())))->toThrow(OrderNotFound::class);
    });

    it('marks test money by the payment', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake, 'total' => $total] = orderPayReady('TEST');
        $request = orderPayAsk($order);
        orderPayPay($number);
        $fake->paid(orderPayAmount($total));
        orderPayFollow($number, $request->requestId);

        expect(DB::table('sales.payments')->where('order_id', $order)->value('is_test'))->toBeTrue();
    });

    it('records a second success as a second payment, and never refuses one on a cancelled order', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake, 'total' => $total] = orderPayReady();
        $request = orderPayAsk($order);
        orderPayPay($number);
        $fake->paid(orderPayAmount($total));
        orderPayFollow($number, $request->requestId);
        $first = (array) DB::table('payments.transactions')->sole();
        unset($first['id']);
        DB::table('payments.transactions')->insert([...$first, 'gateway_payment_id' => 'second-payment']);
        $second = (string) DB::table('payments.transactions')->where('gateway_payment_id', 'second-payment')->value('id');
        Cx::asSuperAdmin(static fn () => app(CancelOrderHandler::class)->handle(new CancelOrder($order, 'Out of stock')));

        app(RecordRequestPayment::class)->handle(new PaymentSucceeded((string) Str::uuid(), $second, $order, Fx::storeId('sa'), CarbonImmutable::now()));

        expect(orderPayOnline($order))->toBe(2)
            ->and(orderPayOutbox(PaymentRecorded::class))->toBe(2)
            ->and((int) DB::table('sales.orders')->where('id', $order)->value('paid_minor'))->toBe($total * 2);
    });

    it('leaves a try\'s order to the try', function () {
        orderPayReady();
        $tried = strtolower((string) Str::ulid());
        DB::table('sales.checkout_tries')->insert([
            'id' => strtolower((string) Str::ulid()), 'store_id' => Fx::storeId('sa'), 'customer_id' => Cx::customer(), 'cart_id' => strtolower((string) Str::ulid()), 'cart_version' => 0,
            'quote_id' => strtolower((string) Str::ulid()), 'order_id' => $tried, 'attempt_id' => strtolower((string) Str::ulid()), 'session_id' => 'KWT-x', 'state' => 'LIVE',
            'amount_minor' => 1, 'currency_code' => 'SAR', 'window_ends_at' => CarbonImmutable::now(), 'created_at' => CarbonImmutable::now(), 'address' => '{}', 'contact' => '{}',
        ]);

        app(RecordRequestPayment::class)->handle(new PaymentSucceeded((string) Str::uuid(), 'not-looked-at', $tried, Fx::storeId('sa'), CarbonImmutable::now()));

        expect(DB::table('sales.payments')->count())->toBe(0)
            ->and(DB::table('public.processed_events')->count())->toBe(0);
    });
});

describe('waiting for a payment in progress', function () {
    it('makes staff edits, setting the fee and hand recording wait, and the customer\'s cancel, but not staff\'s', function () {
        ['order' => $order, 'number' => $number, 'variants' => $variants] = orderPayReady();
        orderPayAsk($order);
        orderPayPay($number);

        expect(fn () => orderPayEdit($order, $variants, 3))->toThrow(PaymentBeingConfirmed::class)
            // Even one that changes no amount: nothing would withdraw the request.
            ->and(fn () => orderPayEdit($order, $variants, 2))->toThrow(PaymentBeingConfirmed::class)
            ->and(fn () => Cx::asSuperAdmin(static fn () => app(SetShippingFeeHandler::class)->handle(new SetShippingFee($order, 5_00, 'Agreed'))))->toThrow(PaymentBeingConfirmed::class)
            ->and(fn () => orderPayRecordByHand($order))->toThrow(PaymentBeingConfirmed::class)
            ->and(fn () => Cx::asSuperAdmin(static fn () => app(WithdrawOrderRequestHandler::class)->handle(new WithdrawOrderRequest($order))))->toThrow(PaymentBeingConfirmed::class)
            ->and(fn () => app(CancelOwnOrderHandler::class)->handle(new CancelOwnOrder($order, 'CHANGED_MIND')))->toThrow(PaymentBeingConfirmed::class);

        $own = app(ViewOwnOrderHandler::class)->handle(new ViewOwnOrder($number));
        expect($own->paymentBeingConfirmed)->toBeTrue()
            ->and($own->mayCancel)->toBeFalse()
            ->and(orderPayStaffView($order)->paymentInProgress)->toBeTrue()
            ->and(orderPayStaffView($order)->mayRequestPayment)->toBeFalse();

        Cx::asSuperAdmin(static fn () => app(CancelOrderHandler::class)->handle(new CancelOrder($order, 'Out of stock')));
        expect(DB::table('sales.orders')->where('id', $order)->value('status'))->toBe('CANCELLED');
    });

    it('refuses a new request while a payment on the order is open', function () {
        ['order' => $order, 'number' => $number] = orderPayReady();
        orderPayAsk($order);
        orderPayPay($number);

        expect(fn () => orderPayAsk($order))->toThrow(PaymentBeingConfirmed::class);
    });

    it('lets a refund through: only payments wait', function () {
        ['order' => $order, 'number' => $number] = orderPayReady();
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => 40_00]);
        orderPayAsk($order);
        orderPayPay($number);

        Cx::asSuperAdmin(static fn () => app(RecordRefundHandler::class)->handle(new RecordRefund($order, '10.00', 'OTHER', null, 'Cash', '2027-01-01', (string) Str::uuid())));

        $view = orderPayStaffView($order);

        // The request no longer matches what is missing, but its payment is still open: nothing new may be asked.
        expect((int) DB::table('sales.orders')->where('id', $order)->value('refunded_minor'))->toBe(10_00)
            ->and($view->paymentRequest)->toBeNull()
            ->and($view->paymentInProgress)->toBeTrue()
            ->and($view->mayRequestPayment)->toBeFalse();
    });

    it('frees the customer\'s cancel once the payment is answered', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake] = orderPayReady();
        $request = orderPayAsk($order);
        orderPayPay($number);
        $fake->declined();
        orderPayFollow($number, $request->requestId);

        app(CancelOwnOrderHandler::class)->handle(new CancelOwnOrder($order, 'CHANGED_MIND'));

        expect(DB::table('sales.orders')->where('id', $order)->value('status'))->toBe('CANCELLED');
    });
});

describe('ending a request', function () {
    it('withdraws the open request when a payment is recorded by hand', function () {
        ['order' => $order] = orderPayReady();
        $request = orderPayAsk($order);

        orderPayRecordByHand($order);

        expect(orderPayState($request->requestId))->toBe('WITHDRAWN')
            ->and(DB::table('sales.payments')->where('order_id', $order)->count())->toBe(1);
    });

    it('leaves it open when the same form of an earlier payment is sent again', function () {
        ['order' => $order] = orderPayReady();
        $form = (string) Str::uuid();
        Cx::asSuperAdmin(static fn () => app(RecordPaymentHandler::class)->handle(new RecordPayment($order, 'OTHER', null, 'Cash', '2027-01-01', $form)));
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => 40_00]);
        $request = orderPayAsk($order);

        Cx::asSuperAdmin(static fn () => app(RecordPaymentHandler::class)->handle(new RecordPayment($order, 'OTHER', null, 'Cash', '2027-01-01', $form)));

        expect(orderPayState($request->requestId))->toBe('OPEN');
    });

    it('withdraws it when an edit changes the amount, and keeps it when it does not', function () {
        ['order' => $order, 'variants' => $variants] = orderPayReady();
        $request = orderPayAsk($order);

        orderPayEdit($order, $variants, 2);
        expect(orderPayState($request->requestId))->toBe('OPEN');

        orderPayEdit($order, $variants, 3);
        expect(orderPayState($request->requestId))->toBe('WITHDRAWN');
    });

    it('withdraws it when setting the fee changes the amount, and keeps it when it does not', function () {
        ['order' => $order] = orderPayReady();
        DB::table('sales.orders')->where('id', $order)->update(['ordered_carrier_id' => null, 'ordered_carrier_ar' => null, 'ordered_carrier_en' => null, 'shipping_fee_minor' => 0, 'shipping_fee_pending' => false]);
        $request = orderPayAsk($order);

        Cx::asSuperAdmin(static fn () => app(SetShippingFeeHandler::class)->handle(new SetShippingFee($order, 0, 'The same')));
        expect(orderPayState($request->requestId))->toBe('OPEN');

        Cx::asSuperAdmin(static fn () => app(SetShippingFeeHandler::class)->handle(new SetShippingFee($order, 5_00, 'Agreed')));
        expect(orderPayState($request->requestId))->toBe('WITHDRAWN');
    });

    it('withdraws it when the order is cancelled, and with a payment open too, whose answer is still recorded', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake, 'total' => $total, 'customer' => $customer] = orderPayReady();
        $request = orderPayAsk($order);
        orderPayPay($number);
        Cx::asSuperAdmin(static fn () => app(CancelOrderHandler::class)->handle(new CancelOrder($order, 'Out of stock')));
        expect(DB::table('public.outbox_messages')->where('type', OrderCancelled::class)->count())->toBe(1);

        app(WithdrawRequestWhenCancelled::class)->handle(new OrderCancelled((string) Str::uuid(), $order, Fx::storeId('sa'), $customer, CarbonImmutable::now()));

        expect(orderPayState($request->requestId))->toBe('WITHDRAWN');

        $fake->paid(orderPayAmount($total));
        app(PaymentsApi::class)->refresh($request->requestId);
        app(RecordRequestPayment::class)->handle(orderPaySucceeded());

        expect(orderPayOnline($order))->toBe(1)
            ->and(orderPayOutbox(PaymentRecorded::class))->toBe(1);
    });
});

describe('what the screens read', function () {
    it('shows the customer the request on their order, and on their list in one read', function () {
        ['order' => $order, 'number' => $number, 'total' => $total] = orderPayReady();
        $request = orderPayAsk($order);

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
        ['order' => $order] = orderPayReady();

        expect(app(ListOwnOrdersHandler::class)->handle(new ListOwnOrders)->orders[0]->paymentRequested)->toBeFalse();

        orderPayAsk($order);
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => 40_00]);

        expect(app(ListOwnOrdersHandler::class)->handle(new ListOwnOrders)->orders[0]->paymentRequested)->toBeFalse();
    });

    it('asks Payments nothing for a page with nothing missing', function () {
        ['order' => $order] = orderPayReady();
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);

        DB::enableQueryLog();
        app(ListOwnOrdersHandler::class)->handle(new ListOwnOrders);
        $reads = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains((string) $query['query'], 'requests'));
        DB::disableQueryLog();

        expect($reads)->toBe([]);
    });

    it('offers staff Request Payment while the order owes and nothing is in the way', function () {
        ['order' => $order] = orderPayReady();

        expect(orderPayStaffView($order)->mayRequestPayment)->toBeTrue()
            ->and(orderPayStaffView($order)->paymentRequest)->toBeNull();

        $request = orderPayAsk($order);
        $view = orderPayStaffView($order);

        expect($view->mayRequestPayment)->toBeFalse()
            ->and($view->paymentRequest?->requestId)->toBe($request->requestId)
            ->and($view->paymentInProgress)->toBeFalse();
    });

    it('does not offer it for what the command would refuse', function (Closure $change) {
        ['order' => $order] = orderPayReady();
        $change($order);

        expect(orderPayStaffView($order)->mayRequestPayment)->toBeFalse();
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
        'a key the gateway refused' => [function (string $order): void {
            DB::table('payments.gateway_keys')->update(['rejected_at' => CarbonImmutable::now()]);
        }],
    ]);

    it('lists the attempts only for staff who may read payments', function () {
        ['order' => $order, 'number' => $number] = orderPayReady();
        $request = orderPayAsk($order);
        orderPayPay($number);

        $attempts = orderPayStaffView($order)->attempts ?? [];
        expect($attempts)->toHaveCount(1)
            ->and($attempts[0]->ownerId ?? null)->toBe($request->requestId);

        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']));
        expect(app(ViewOrderHandler::class)->handle(new ViewOrder($order))->attempts)->toBeNull();

        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW, ViewOrderHandler::ATTEMPTS_PERMISSION], ['sa']));
        expect(app(ViewOrderHandler::class)->handle(new ViewOrder($order))->attempts)->toHaveCount(1);
    });

    it('keeps staff\'s online payment rows and offers nothing once it is paid', function () {
        ['order' => $order, 'number' => $number, 'fake' => $fake, 'total' => $total] = orderPayReady();
        $request = orderPayAsk($order);
        orderPayPay($number);
        $fake->paid(orderPayAmount($total));
        orderPayFollow($number, $request->requestId);

        $view = orderPayStaffView($order);

        expect($view->paymentRequest)->toBeNull()
            ->and($view->paymentInProgress)->toBeFalse()
            ->and($view->mayRequestPayment)->toBeFalse()
            ->and($view->attempts)->toHaveCount(1);
    });
});

/** The order turned into a bank transfer's, its receipt uploaded now by its customer: only the columns matter here. */
function orderPayTransfer(string $order): void
{
    DB::table('sales.orders')->where('id', $order)->update(['way_to_pay' => 'BANK_TRANSFER']);
    app(UploadTransferDocumentHandler::class)->handle(new UploadTransferDocument($order, Bx::pdf(), 'transfer.pdf'));
}

/**
 * Another real transfer whose receipt comes now, so its store is still looked at when the first order has nothing left
 * to tell.
 *
 * @param  list<string>  $variants
 */
function orderPayAnotherTransfer(array $variants, string $address): void
{
    Cx::add($variants[0], 1);
    orderPayTransfer(Cx::place(Cx::start($address)->id)->id);
}

/** @return list<StaffOrderRow> */
function orderPayOverdueRows(): array
{
    return Cx::asSuperAdmin(static fn (): array => app(ListOrdersHandler::class)->handle(new ListOrders)->orders);
}

describe('a transfer past its check time', function () {
    it('is told once per order after the store\'s hours, and marked for staff', function () {
        ['order' => $order, 'variants' => $variants, 'address' => $address] = orderPayReady();
        orderPayTransfer($order);

        orderPayAt('+71 hours');
        app(TransferOverdueCheck::class)->run();
        expect(orderPayOutbox(TransferOverdue::class))->toBe(0)
            ->and(orderPayOverdueRows()[0]->isOverdue)->toBeFalse()
            ->and(orderPayStaffView($order)->isOverdue)->toBeFalse();

        orderPayAt('+72 hours');
        orderPayAnotherTransfer($variants, $address);
        app(TransferOverdueCheck::class)->run();
        app(TransferOverdueCheck::class)->run();

        $payload = json_decode((string) DB::table('public.outbox_messages')->where('type', TransferOverdue::class)->value('payload'), true);
        expect(orderPayOutbox(TransferOverdue::class))->toBe(1)
            ->and($payload)->toMatchArray(['orderId' => $order, 'storeId' => Fx::storeId('sa')])
            ->and(DB::table('sales.orders')->where('id', $order)->value('overdue_told_at'))->not->toBeNull()
            ->and(orderPayOverdueRows()[1]->isOverdue)->toBeTrue()
            ->and(orderPayOverdueRows()[0]->isOverdue)->toBeFalse()
            ->and(orderPayStaffView($order)->isOverdue)->toBeTrue();
    });

    it('counts from the first receipt, by the store\'s own setting', function () {
        ['order' => $order] = orderPayReady();
        orderPayTransfer($order);
        orderPayAt('+12 hours');
        app(UploadTransferDocumentHandler::class)->handle(new UploadTransferDocument($order, Bx::pdf(), 'second.pdf'));
        Cx::setting('payments.transfer.check_hours', 10);

        app(TransferOverdueCheck::class)->run();

        expect(orderPayOutbox(TransferOverdue::class))->toBe(1);
    });

    it('leaves out what is paid, a test order, another way to pay and an order with no receipt', function (Closure $change) {
        ['order' => $order, 'variants' => $variants, 'address' => $address] = orderPayReady();
        orderPayTransfer($order);
        $change($order);
        orderPayAt('+73 hours');
        orderPayAnotherTransfer($variants, $address);

        app(TransferOverdueCheck::class)->run();

        expect(orderPayOutbox(TransferOverdue::class))->toBe(0)
            ->and(orderPayOverdueRows()[1]->isOverdue)->toBeFalse()
            ->and(orderPayStaffView($order)->isOverdue)->toBeFalse();
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
        ['order' => $order] = orderPayReady();

        expect(app(Orders::class)->storesWithUncheckedTransfers())->toBe([]);

        orderPayTransfer($order);

        expect(app(Orders::class)->storesWithUncheckedTransfers())->toBe([Fx::storeId('sa')]);
    });
});

it('wakes nothing on an order with no request, for the helper the screens lean on', function () {
    ['order' => $order] = orderPayReady();

    expect(app(OrderRequests::class)->live($order, 0))->toBeNull()
        ->and(app(OrderRequests::class)->live($order, 1))->toBeNull();
});
