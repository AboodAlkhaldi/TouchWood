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
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Application\Alerts\EventSender;
use Modules\Ops\Application\DailyReport\DailyReport;
use Modules\Ops\Application\DailyReport\DailyReportPeople;
use Modules\Ops\Application\DailyReport\DailyReportReader;
use Modules\Ops\Application\DailyReport\DailyReportRenderer;
use Modules\Ops\Domain\Model\SendStatus;
use Modules\Ops\Domain\Model\SentMessage;
use Modules\Ops\Domain\Repository\SentMessages;
use Modules\Ops\Infrastructure\Pdf\ReportFormat;
use Modules\Ops\Public\Enums\MessageChannel;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Public\Dto\PaymentRequestStateDto;
use Modules\Payments\Public\Events\PaymentRequested;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\CancelOrder\CancelOrder;
use Modules\Sales\Application\Command\CancelOrder\CancelOrderHandler;
use Modules\Sales\Application\Command\FollowCheckoutPayment\FollowCheckoutPayment;
use Modules\Sales\Application\Command\FollowCheckoutPayment\FollowCheckoutPaymentHandler;
use Modules\Sales\Application\Command\FollowRequestPayment\FollowRequestPayment;
use Modules\Sales\Application\Command\FollowRequestPayment\FollowRequestPaymentHandler;
use Modules\Sales\Application\Command\MarkCameBack\MarkCameBack;
use Modules\Sales\Application\Command\MarkCameBack\MarkCameBackHandler;
use Modules\Sales\Application\Command\OpenCheckoutPayment\OpenCheckoutPayment;
use Modules\Sales\Application\Command\OpenCheckoutPayment\OpenCheckoutPaymentHandler;
use Modules\Sales\Application\Command\OpenRequestPayment\OpenRequestPayment;
use Modules\Sales\Application\Command\OpenRequestPayment\OpenRequestPaymentHandler;
use Modules\Sales\Application\Command\PayOnline\PayOnline;
use Modules\Sales\Application\Command\PayOnline\PayOnlineHandler;
use Modules\Sales\Application\Command\PayOrderRequest\PayOrderRequest;
use Modules\Sales\Application\Command\PayOrderRequest\PayOrderRequestHandler;
use Modules\Sales\Application\Command\RecordPayment\RecordPayment;
use Modules\Sales\Application\Command\RecordPayment\RecordPaymentHandler;
use Modules\Sales\Application\Command\RecordRefund\RecordRefund;
use Modules\Sales\Application\Command\RecordRefund\RecordRefundHandler;
use Modules\Sales\Application\Command\RejectOrder\RejectOrder;
use Modules\Sales\Application\Command\RejectOrder\RejectOrderHandler;
use Modules\Sales\Application\Command\RequestOrderPayment\RequestOrderPayment;
use Modules\Sales\Application\Command\RequestOrderPayment\RequestOrderPaymentHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\Command\UploadTransferDocument\UploadTransferDocument;
use Modules\Sales\Application\Command\UploadTransferDocument\UploadTransferDocumentHandler;
use Modules\Sales\Application\Command\WithdrawOrderRequest\WithdrawOrderRequest;
use Modules\Sales\Application\Command\WithdrawOrderRequest\WithdrawOrderRequestHandler;
use Modules\Sales\Application\Order\TransferOverdueCheck;
use Modules\Sales\Application\SalesSettings;
use Modules\Sales\Public\Events\OrderEdited;
use Modules\Sales\Public\Events\PaymentRecorded;
use Modules\Sales\Public\Events\TransferOverdue;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Outbox\OutboxRelay;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\B2B\Support\B2BFixtures as Bx;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\PdfText;
use Tests\Modules\Ops\Support\WrittenFixtures as Wx;
use Tests\Modules\Payments\Support\FakeMyFatoorah;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;
use Tests\TestCase;

use function Pest\Laravel\seed;

/*
| Stage 7's last moments in Ops (ops.md §1.5 C10, C11; §1.6 S6; §1.11's transfers overdue): nothing here stands in
| for Sales or Payments. Staff really ask, the customer really pays through the fake gateway, Sales' hourly check
| really fires, and the events go through the real outbox and its relay, so their payloads are proved too. The queue
| is `sync`: an event's emails have gone when the relay returns.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    app()->instance(KeyTester::class, new class implements KeyTester
    {
        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void {}
    });
    Sleep::fake();
    RateLimiter::clear('payments-checks');
    CapturedEmails::install();
});

afterEach(function () {
    File::deleteDirectory(Bx::uploads());
});

/** The clock, from the moment every test starts at. */
function paymentEventsAt(string $when): void
{
    $moment = CarbonImmutable::parse(TestCase::FROZEN_AT)->modify($when);
    CarbonImmutable::setTestNow($moment);
    Carbon::setTestNow($moment);
}

/** Everything waiting in the outbox goes, and the emails it sent are forgotten: only what comes next is looked at. */
function paymentEventsDrain(): void
{
    app(OutboxRelay::class)->run();
    CapturedEmails::installed()->sent = [];
}

function paymentEventsGateway(string $mode = 'LIVE'): FakeMyFatoorah
{
    $fake = FakeMyFatoorah::install();
    Cx::asSuperAdmin(function () use ($mode): void {
        app(ChangeKeysHandler::class)->handle(new ChangeKeys(Fx::storeId('sa'), $mode, 'sk_key_ABC123', 'whsec_secret_1', '2030-12-31'));
        app(SwitchGatewayHandler::class)->handle(new SwitchGateway(Fx::storeId('sa'), true));
    });

    return $fake;
}

/**
 * The store's gateway on, and an individual's order placed and told of: acting as the customer.
 *
 * @return array{fake: FakeMyFatoorah, order: string, number: string, total: int, customer: string, variants: list<string>, address: string}
 */
function paymentEventsReady(): array
{
    $fake = paymentEventsGateway();
    ['customer' => $customer, 'address' => $address, 'variants' => $variants] = Cx::ready();
    $placed = Cx::place(Cx::start($address)->id);
    paymentEventsDrain();

    return [
        'fake' => $fake, 'order' => $placed->id, 'number' => $placed->number, 'customer' => $customer, 'variants' => $variants, 'address' => $address,
        'total' => (int) DB::table('sales.orders')->where('id', $placed->id)->value('order_total_minor'),
    ];
}

function paymentEventsAsk(string $order): PaymentRequestStateDto
{
    return Cx::asSuperAdmin(static fn (): PaymentRequestStateDto => app(RequestOrderPaymentHandler::class)->handle(new RequestOrderPayment($order)));
}

/** The latest `PaymentRequested` as it went into the outbox. */
function paymentEventsRequested(): PaymentRequested
{
    $row = DB::table('public.outbox_messages')->where('type', PaymentRequested::class)->orderByDesc('id')->first() ?? throw new LogicException('No such event.');
    /** @var array{eventId: string, requestId: string, orderId: string, storeId: string, customerId: string, expiresAt: string, occurredAt: string} $payload */
    $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);

    return new PaymentRequested($payload['eventId'], $payload['requestId'], $payload['orderId'], $payload['storeId'], $payload['customerId'], CarbonImmutable::parse($payload['expiresAt']), CarbonImmutable::parse($payload['occurredAt']));
}

/** The customer pays the request through the fake gateway, and the page sees it: an `ONLINE` payment on the order. */
function paymentEventsPayRequest(FakeMyFatoorah $fake, string $number, string $requestId, int $minor): void
{
    $store = Fx::storeId('sa');
    $session = app(OpenRequestPaymentHandler::class)->handle(new OpenRequestPayment($store, $number, 'en'))->sessionId;
    app(PayOrderRequestHandler::class)->handle(new PayOrderRequest($store, $number, $session, 'en', 'https://shop.example.test/return'));
    $fake->paid(number_format($minor / 100, 2, '.', ''));
    app(FollowRequestPaymentHandler::class)->handle(new FollowRequestPayment($store, $number, $requestId));
}

/** The order approved, shipped and come back, by staff: UNDELIVERED. */
function paymentEventsCameBack(string $order): void
{
    Cx::asSuperAdmin(static function () use ($order): void {
        app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
        app(ShipOrderHandler::class)->handle(new ShipOrder($order, otherCarrier: 'SMSA', trackingNumber: 'T-100'));
        app(MarkCameBackHandler::class)->handle(new MarkCameBack($order, []));
    });
    expect(DB::table('sales.orders')->where('id', $order)->value('status'))->toBe('UNDELIVERED');
}

function paymentEventsEmailOf(string $customer): string
{
    return (string) DB::table('access.customers')->where('id', $customer)->value('email');
}

function paymentEventsMoney(int $minor, string $locale): string
{
    return number_format($minor / 100, 2, '.', '').' '.paymentEventsStore('sa')->currencyAbbreviation->in($locale);
}

function paymentEventsStore(string $code): StoreDto
{
    return app(PlatformApi::class)->store(StoreId::fromString(Fx::storeId($code))) ?? throw new LogicException('No such store.');
}

/** @return list<object{kind: string, title_en: string, title_ar: string, line_en: string, line_ar: string, link: ?string}> */
function paymentEventsBell(string $kind): array
{
    /** @var list<object{kind: string, title_en: string, title_ar: string, line_en: string, line_ar: string, link: ?string}> */
    return DB::table('ops.inbox_entries')->where('kind', $kind)->orderBy('created_at')->get()->all();
}

/** @return list<string> "CHANNEL STATUS" */
function paymentEventsSent(string $kind): array
{
    return array_values(DB::table('ops.sent_messages')->where('kind', $kind)->get()->map(static fn (object $row): string => "{$row->channel} {$row->status}")->all());
}

/** The order turned into a bank transfer's, its receipt uploaded now by its customer: only the columns matter here. */
function paymentEventsTransfer(string $order): void
{
    DB::table('sales.orders')->where('id', $order)->update(['way_to_pay' => 'BANK_TRANSFER']);
    app(UploadTransferDocumentHandler::class)->handle(new UploadTransferDocument($order, Bx::pdf(), 'transfer.pdf'));
}

/** @param  list<string>  $variants */
function paymentEventsAnother(array $variants, string $address): string
{
    Cx::add($variants[0], 1);

    return Cx::place(Cx::start($address)->id)->id;
}

function paymentEventsDue(string $order): int
{
    return (int) DB::table('sales.orders')->where('id', $order)->value('due_minor');
}

/** The report's HTML as text: the tags gone, the spaces collapsed - "Orders: 1 , owed 287.50 SAR", a number's own tag leaving its space. */
function paymentEventsReportText(DailyReport $report, string $locale = 'en'): string
{
    $html = view('ops::daily-report', ['report' => $report, 'locale' => $locale, 'rtl' => $locale === 'ar', 'f' => new ReportFormat($locale), 'logo' => public_path('icons/email-logo.png')])->render();

    return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/<[^>]+>/', ' ', $html)));
}

/**
 * One order overdue, at exactly its 72 hours; three with as old a receipt that do not count (a test order, one paid,
 * one cancelled); one with no receipt at all; one whose receipt is an hour younger.
 *
 * @return array{overdue: string, younger: string}
 */
function paymentEventsOverdueDay(): array
{
    ['order' => $overdue, 'variants' => $variants, 'address' => $address] = paymentEventsReady();
    // A customer may have only so many orders waiting: this one has more, to be told apart.
    Fx::asSystem(static fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting(SalesSettings::WAITING_ORDERS, null, 20)));
    paymentEventsTransfer($overdue);

    $test = paymentEventsAnother($variants, $address);
    paymentEventsTransfer($test);
    DB::table('sales.orders')->where('id', $test)->update(['is_test' => true]);

    $paid = paymentEventsAnother($variants, $address);
    paymentEventsTransfer($paid);
    DB::table('sales.orders')->where('id', $paid)->update(['paid_minor' => DB::raw('due_minor')]);

    $cancelled = paymentEventsAnother($variants, $address);
    paymentEventsTransfer($cancelled);
    Cx::asSuperAdmin(static fn () => app(CancelOrderHandler::class)->handle(new CancelOrder($cancelled, 'Out of stock')));

    $noReceipt = paymentEventsAnother($variants, $address);
    DB::table('sales.orders')->where('id', $noReceipt)->update(['way_to_pay' => 'BANK_TRANSFER']);

    paymentEventsAt('+1 hour');
    $younger = paymentEventsAnother($variants, $address);
    paymentEventsTransfer($younger);

    paymentEventsAt('+72 hours');

    return ['overdue' => $overdue, 'younger' => $younger];
}

describe('C11: pay the remaining amount', function () {
    it('sends the customer the link through the outbox, by bell and email in their language, once per event', function (string $locale, string $title, string $line, string $subject, string $words, string $action) {
        ['order' => $order, 'number' => $number, 'total' => $total, 'customer' => $customer] = paymentEventsReady();
        Wx::set($customer, ['locale' => $locale]);
        paymentEventsAsk($order);

        app(OutboxRelay::class)->run();

        $amount = paymentEventsMoney($total, $locale);
        $link = "/sa/{$locale}/orders/{$number}/pay";
        $bell = paymentEventsBell('pay_remaining');
        $email = CapturedEmails::installed()->for(paymentEventsEmailOf($customer));

        expect($bell)->toHaveCount(1)
            ->and($bell[0]->link)->toBe($link)
            ->and($locale === 'ar' ? $bell[0]->title_ar : $bell[0]->title_en)->toBe(str_replace(':order', $number, $title))
            ->and($locale === 'ar' ? $bell[0]->line_ar : $bell[0]->line_en)->toBe(str_replace(':amount', paymentEventsMoney($total, $locale), $line))
            ->and(CapturedEmails::installed()->sent)->toHaveCount(1)
            ->and($email->subject)->toBe(str_replace(':order', $number, $subject))
            ->and($email->text)->toContain(str_replace([':amount', ':order'], [$amount, $number], $words))
            ->and($email->text)->toContain($action.': '.rtrim((string) config('app.url'), '/').$link)
            ->and($email->html)->toContain($action)
            ->and(paymentEventsSent('pay_remaining'))->toBe(['EMAIL SENT'])
            ->and(DB::table('public.outbox_messages')->whereNull('dispatched_at')->count())->toBe(0);

        // A replay of the same event, as the outbox allows: nothing more.
        event(paymentEventsRequested());
        app(OutboxRelay::class)->run();

        expect(paymentEventsBell('pay_remaining'))->toHaveCount(1)
            ->and(CapturedEmails::installed()->sent)->toHaveCount(1);
    })->with([
        'English' => ['en', 'Pay the rest of order :order', ':amount is left to pay. The link works for 60 minutes.', 'Pay the remaining amount of order :order', ':amount is left to pay for order :order.', 'Pay now'],
        'Arabic' => ['ar', 'ادفع المتبقي من الطلب :order', 'المتبقي :amount. الرابط صالح لمدة 60 دقيقة.', 'ادفع المبلغ المتبقي من الطلب :order', 'المتبقي للدفع من الطلب :order هو :amount.', 'ادفع الآن'],
    ]);

    it('asks only what is left of an order partly paid', function () {
        ['order' => $order, 'total' => $total, 'customer' => $customer] = paymentEventsReady();
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => 40_00]);
        paymentEventsAsk($order);

        app(OutboxRelay::class)->run();

        expect(CapturedEmails::installed()->for(paymentEventsEmailOf($customer))->text)->toContain(paymentEventsMoney($total - 40_00, 'en').' is left to pay');
    });

    it('sends nothing when the moment has passed or the read names someone else', function (Closure $change) {
        ['fake' => $fake, 'order' => $order, 'number' => $number] = paymentEventsReady();
        paymentEventsAsk($order);

        event($change(paymentEventsRequested(), $order, $fake, $number));

        expect(paymentEventsBell('pay_remaining'))->toBe([])
            ->and(paymentEventsSent('pay_remaining'))->toBe([])
            ->and(CapturedEmails::installed()->sent)->toBe([]);
    })->with([
        'withdrawn' => [function (PaymentRequested $event, string $order): PaymentRequested {
            Cx::asSuperAdmin(static fn () => app(WithdrawOrderRequestHandler::class)->handle(new WithdrawOrderRequest($order)));

            return $event;
        }],
        'past its 60 minutes' => [function (PaymentRequested $event): PaymentRequested {
            paymentEventsAt('+60 minutes');

            return $event;
        }],
        'a newer request in its place' => [function (PaymentRequested $event, string $order): PaymentRequested {
            Cx::asSuperAdmin(static fn () => app(WithdrawOrderRequestHandler::class)->handle(new WithdrawOrderRequest($order)));
            paymentEventsAsk($order);

            return $event;
        }],
        'the order cancelled' => [function (PaymentRequested $event, string $order): PaymentRequested {
            Cx::asSuperAdmin(static fn () => app(CancelOrderHandler::class)->handle(new CancelOrder($order, 'Out of stock')));

            return $event;
        }],
        'the order rejected' => [function (PaymentRequested $event, string $order): PaymentRequested {
            Cx::asSuperAdmin(static fn () => app(RejectOrderHandler::class)->handle(new RejectOrder($order, 'Out of the delivery area')));

            return $event;
        }],
        'the order came back' => [function (PaymentRequested $event, string $order): PaymentRequested {
            paymentEventsCameBack($order);

            return $event;
        }],
        'paid' => [function (PaymentRequested $event, string $order, FakeMyFatoorah $fake, string $number): PaymentRequested {
            paymentEventsPayRequest($fake, $number, $event->requestId, (int) DB::table('payments.requests')->where('id', $event->requestId)->value('amount_minor'));
            expect(DB::table('payments.requests')->where('id', $event->requestId)->value('state'))->toBe('PAID');

            return $event;
        }],
        'an order gone' => [function (PaymentRequested $event): PaymentRequested {
            return new PaymentRequested((string) Str::uuid(), $event->requestId, strtolower((string) Str::ulid()), $event->storeId, $event->customerId, $event->expiresAt, $event->occurredAt);
        }],
        'another customer\'s' => [function (PaymentRequested $event): PaymentRequested {
            $omar = Fx::customer('omar@example.test');
            // Registering sent him the confirmation of his address.
            CapturedEmails::installed()->sent = [];

            return new PaymentRequested((string) Str::uuid(), $event->requestId, $event->orderId, $event->storeId, $omar, $event->expiresAt, $event->occurredAt);
        }],
        'another store\'s' => [function (PaymentRequested $event): PaymentRequested {
            return new PaymentRequested((string) Str::uuid(), $event->requestId, $event->orderId, Fx::storeId('eg'), $event->customerId, $event->expiresAt, $event->occurredAt);
        }],
    ]);

    it('checks again when the email leaves: a request ended since sends nothing, and its row goes', function (Closure $change) {
        ['order' => $order, 'customer' => $customer] = paymentEventsReady();
        $request = paymentEventsAsk($order);
        $message = new EventMessage(MessageKind::PayRemaining, Fx::storeId('sa'), ['requestId' => $request->requestId, 'orderId' => $order]);
        $sent = strtolower((string) Str::ulid());
        app(SentMessages::class)->record(new SentMessage($sent, MessageKind::PayRemaining, $customer, null, StoreId::fromString(Fx::storeId('sa')), MessageChannel::Email, null, SendStatus::Queued, CarbonImmutable::now()->toDateTimeImmutable()));

        $change($order);
        app(EventSender::class)->emailCustomer($message, $customer, $sent);

        expect(CapturedEmails::installed()->sent)->toBe([])
            ->and(paymentEventsSent('pay_remaining'))->toBe([]);
    })->with([
        'withdrawn' => [function (string $order): void {
            Cx::asSuperAdmin(static fn () => app(WithdrawOrderRequestHandler::class)->handle(new WithdrawOrderRequest($order)));
        }],
        'past its 60 minutes' => [function (): void {
            paymentEventsAt('+61 minutes');
        }],
    ]);
});

describe('C10: a payment received', function () {
    it('tells the customer of a request\'s online payment once, in their language, however often it is answered', function (string $locale, string $title, string $words) {
        ['fake' => $fake, 'order' => $order, 'number' => $number, 'total' => $total, 'customer' => $customer] = paymentEventsReady();
        Wx::set($customer, ['locale' => $locale]);
        $request = paymentEventsAsk($order);
        paymentEventsDrain();
        paymentEventsPayRequest($fake, $number, $request->requestId, $total);
        $follow = static fn () => app(FollowRequestPaymentHandler::class)->handle(new FollowRequestPayment(Fx::storeId('sa'), $number, $request->requestId));

        // The gateway's own event comes through the relay too, and the page is looked at again.
        app(OutboxRelay::class)->run();
        $follow();
        app(OutboxRelay::class)->run();

        $amount = paymentEventsMoney($total, $locale);
        $bell = paymentEventsBell('payment_received');

        expect(DB::table('sales.payments')->where('order_id', $order)->value('way'))->toBe('ONLINE')
            ->and(DB::table('public.outbox_messages')->where('type', PaymentRecorded::class)->count())->toBe(1)
            ->and($bell)->toHaveCount(1)
            ->and($bell[0]->link)->toBe("/sa/{$locale}/account/orders/{$number}")
            ->and($locale === 'ar' ? $bell[0]->title_ar : $bell[0]->title_en)->toBe(str_replace(':order', $number, $title))
            ->and(paymentEventsSent('payment_received'))->toBe(['EMAIL SENT'])
            ->and(CapturedEmails::installed()->for(paymentEventsEmailOf($customer))->text)->toContain(str_replace([':amount', ':order'], [$amount, $number], $words));
    })->with([
        'English' => ['en', 'Payment received for order :order', 'We received your payment of :amount for order :order. Thank you.'],
        'Arabic' => ['ar', 'استلمنا دفعة الطلب :order', 'استلمنا دفعتك بمبلغ :amount للطلب :order. شكرًا لك.'],
    ]);

    it('sends no C10 for a checkout\'s own payment: that customer gets C1 alone', function () {
        $fake = paymentEventsGateway();
        ['customer' => $customer, 'address' => $address] = Cx::ready();
        $quote = Cx::start($address, 'ONLINE');
        $store = Fx::storeId('sa');
        $session = app(OpenCheckoutPaymentHandler::class)->handle(new OpenCheckoutPayment($store, $quote->id, 'en'))->sessionId;
        $paid = app(PayOnlineHandler::class)->handle(new PayOnline($store, $quote->id, $session, 'en', 'https://shop.example.test/return'));
        $fake->paid($quote->totals->orderTotal->toDecimal(2));

        app(FollowCheckoutPaymentHandler::class)->handle(new FollowCheckoutPayment($paid->tryId));
        app(OutboxRelay::class)->run();
        app(OutboxRelay::class)->run();

        expect(DB::table('sales.payments')->sole()->way)->toBe('ONLINE')
            ->and(DB::table('public.outbox_messages')->where('type', PaymentRecorded::class)->count())->toBe(0)
            ->and(paymentEventsBell('payment_received'))->toBe([])
            ->and(paymentEventsSent('payment_received'))->toBe([])
            ->and(paymentEventsBell('order_taken'))->toHaveCount(1)
            ->and(CapturedEmails::installed()->to())->toContain(paymentEventsEmailOf($customer))
            ->and(paymentEventsSent('order_taken'))->toBe(['EMAIL SENT']);
    });
});

describe('a test order says so (payments.md §1.6)', function () {
    it('marks C1, C11 and C10 in the bell and the email, in the customer\'s language', function (string $locale, string $mark, string $orderTaken) {
        $fake = paymentEventsGateway('TEST');
        ['customer' => $customer, 'address' => $address] = Cx::ready();
        Wx::set($customer, ['locale' => $locale]);
        $placed = Cx::place(Cx::start($address)->id);
        $line = static fn (string $kind): string => (string) ($locale === 'ar' ? paymentEventsBell($kind)[0]->line_ar : paymentEventsBell($kind)[0]->line_en);

        app(OutboxRelay::class)->run();

        expect($placed->number)->toStartWith('TEST-')
            ->and(DB::table('sales.orders')->where('id', $placed->id)->value('is_test'))->toBeTrue()
            ->and($line('order_taken'))->toBe($orderTaken.' '.$mark)
            // Under the greeting, before anything else the email says.
            ->and(explode("\n\n", CapturedEmails::installed()->last()->text)[1])->toBe($mark);

        $request = paymentEventsAsk($placed->id);
        app(OutboxRelay::class)->run();

        expect($line('pay_remaining'))->toEndWith(' '.$mark)
            ->and(CapturedEmails::installed()->last()->subject)->toContain($placed->number)
            ->and(CapturedEmails::installed()->last()->text)->toContain($mark)->toContain("/orders/{$placed->number}/pay");

        paymentEventsPayRequest($fake, $placed->number, $request->requestId, $request->amount->minorUnits);
        // Twice: the relay holds PaymentRecorded back until the order's earlier PaymentSucceeded has gone.
        app(OutboxRelay::class)->run();
        app(OutboxRelay::class)->run();

        expect($line('payment_received'))->toEndWith(' '.$mark)
            ->and(CapturedEmails::installed()->last()->text)->toContain($mark);
    })->with([
        'English' => ['en', 'This is a test order.', 'We received your order and will review it soon.'],
        'Arabic' => ['ar', 'هذا طلب تجريبي.', 'استلمنا طلبك وسنراجعه قريبًا.'],
    ]);

    it('marks C6b and C9b too', function () {
        paymentEventsGateway('TEST');
        ['customer' => $customer, 'address' => $address] = Cx::ready();
        $placed = Cx::place(Cx::start($address)->id);
        app(OutboxRelay::class)->run();
        $day = CarbonImmutable::now('Asia/Riyadh')->format('Y-m-d');

        event(new OrderEdited((string) Str::uuid(), strtolower((string) Str::ulid()), $placed->id, Fx::storeId('sa'), $customer, CarbonImmutable::now()));
        $changed = CapturedEmails::installed()->last();

        Cx::asSuperAdmin(static function () use ($placed, $day): void {
            app(RecordPaymentHandler::class)->handle(new RecordPayment($placed->id, 'OTHER', null, 'Cash', $day, (string) Str::uuid()));
            app(RecordRefundHandler::class)->handle(new RecordRefund($placed->id, '10.00', 'BANK_TRANSFER', 'T-2', null, $day, (string) Str::uuid()));
        });
        // Twice: the relay holds RefundRecorded back until the order's earlier PaymentRecorded has gone.
        app(OutboxRelay::class)->run();
        app(OutboxRelay::class)->run();
        $refund = CapturedEmails::installed()->last();

        expect(paymentEventsBell('order_changed')[0]->line_en)->toEndWith(' This is a test order.')
            ->and(explode("\n\n", $changed->text)[1])->toBe('This is a test order.')
            ->and(paymentEventsBell('refund_sent')[0]->line_en)->toEndWith(' This is a test order.')
            ->and($refund->subject)->toContain($placed->number)
            ->and(explode("\n\n", $refund->text)[1])->toBe('This is a test order.');
    });

    it('puts no mark on a real order\'s messages', function () {
        $fake = paymentEventsGateway();
        ['customer' => $customer, 'address' => $address] = Cx::ready();
        $placed = Cx::place(Cx::start($address)->id);

        app(OutboxRelay::class)->run();
        $request = paymentEventsAsk($placed->id);
        app(OutboxRelay::class)->run();
        paymentEventsPayRequest($fake, $placed->number, $request->requestId, $request->amount->minorUnits);
        // Twice: the relay holds PaymentRecorded back until the order's earlier PaymentSucceeded has gone.
        app(OutboxRelay::class)->run();
        app(OutboxRelay::class)->run();

        $emails = array_filter(CapturedEmails::installed()->sent, static fn ($email): bool => $email->toEmail === paymentEventsEmailOf($customer));

        expect(paymentEventsSent('order_taken'))->toBe(['EMAIL SENT'])
            ->and(paymentEventsSent('pay_remaining'))->toBe(['EMAIL SENT'])
            ->and(paymentEventsSent('payment_received'))->toBe(['EMAIL SENT'])
            ->and(array_filter($emails, static fn ($email): bool => str_contains($email->text, 'test order')))->toBe([])
            ->and(DB::table('ops.inbox_entries')->where('customer_id', $customer)->where('line_en', 'like', '%test order%')->count())->toBe(0);
    });
});

describe('S6: a transfer overdue', function () {
    it('alerts the holders of sales.payment.record in the store from the hourly check, by their switches, once per event', function () {
        ['order' => $order, 'number' => $number] = paymentEventsReady();
        paymentEventsTransfer($order);
        $byDefault = Fx::staffWith(['sales.payment.record', 'sales.order.view'], ['sa']);
        $byEmail = Fx::staffWith(['sales.payment.record', 'sales.order.view'], ['sa']);
        $arabic = Fx::staffWith(['sales.payment.record', 'sales.order.view'], ['sa']);
        $elsewhere = Fx::staffWith(['sales.payment.record', 'sales.order.view'], ['eg']);
        $noPermission = Fx::staffWith(['sales.order.view'], ['sa']);
        DB::table('access.staff_notification_preferences')->insert([
            ['staff_user_id' => $byEmail, 'topic' => StaffNotificationTopic::PaymentsToCheck->value, 'panel' => false, 'email' => true],
            ['staff_user_id' => $arabic, 'topic' => StaffNotificationTopic::PaymentsToCheck->value, 'panel' => false, 'email' => true],
        ]);
        DB::table('access.staff_users')->where('id', $arabic)->update(['locale' => 'ar']);
        cache()->flush();

        paymentEventsAt('+72 hours');
        app(TransferOverdueCheck::class)->run();
        app(OutboxRelay::class)->run();

        $bellOf = static fn (string ...$staff) => DB::table('ops.inbox_entries')->where('kind', 'transfer_overdue')->whereIn('staff_id', $staff)->get();
        $bell = $bellOf($byDefault)->sole();
        $to = static fn (string $staff): string => (string) DB::table('access.staff_users')->where('id', $staff)->value('email');
        $link = rtrim((string) config('app.url'), '/').'/admin/orders/'.$order;

        expect([$bell->title_en, $bell->line_en, $bell->link, $bell->store_id])->toBe(["Transfer to check for order {$number}", 'A bank transfer has waited 72 hours for a check.', '/admin/orders/'.$order, Fx::storeId('sa')])
            ->and([$bell->title_ar, $bell->line_ar])->toBe(["تحويل للمراجعة في الطلب {$number}", 'تحويل بنكي ينتظر المراجعة منذ 72 ساعة.'])
            ->and(CapturedEmails::installed()->to())->toEqualCanonicalizing([$to($byEmail), $to($arabic)])
            ->and(CapturedEmails::installed()->for($to($byEmail))->subject)->toBe("A bank transfer waits for a check: order {$number}")
            ->and(CapturedEmails::installed()->for($to($byEmail))->text)->toContain("The bank transfer receipt of order {$number} in Saudi Arabia has waited 72 hours for a check.")->toContain($link)
            ->and(CapturedEmails::installed()->for($to($arabic))->subject)->toBe("تحويل بنكي ينتظر المراجعة: الطلب {$number}")
            ->and($bellOf($byEmail, $arabic, $elsewhere, $noPermission))->toHaveCount(0);

        // The check again, and a replay of the event: Sales fires once, and Ops sends once.
        app(TransferOverdueCheck::class)->run();
        $row = DB::table('public.outbox_messages')->where('type', TransferOverdue::class)->sole();
        /** @var array{eventId: string, orderId: string, storeId: string} $payload */
        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
        event(new TransferOverdue($payload['eventId'], $payload['orderId'], $payload['storeId'], CarbonImmutable::now()));
        app(OutboxRelay::class)->run();

        expect($bellOf($byDefault))->toHaveCount(1)
            ->and(CapturedEmails::installed()->sent)->toHaveCount(2);
    });

    it('says the store\'s own check time, in both languages', function (int $hours, string $english, string $arabic) {
        ['order' => $order] = paymentEventsReady();
        Cx::setting('payments.transfer.check_hours', $hours);
        paymentEventsTransfer($order);
        $staff = Fx::staffWith(['sales.payment.record', 'sales.order.view'], ['sa']);

        paymentEventsAt("+{$hours} hours");
        app(TransferOverdueCheck::class)->run();
        app(OutboxRelay::class)->run();

        $bell = DB::table('ops.inbox_entries')->where('kind', 'transfer_overdue')->where('staff_id', $staff)->sole();

        expect($bell->line_en)->toBe("A bank transfer has waited {$english} for a check.")
            ->and($bell->line_ar)->toBe("تحويل بنكي ينتظر المراجعة منذ {$arabic}.");
    })->with([
        'one' => [1, '1 hour', 'ساعة واحدة'],
        'two' => [2, '2 hours', 'ساعتين'],
        'a few' => [5, '5 hours', '5 ساعات'],
        'a day' => [24, '24 hours', '24 ساعة'],
    ]);

    it('gives no link to whoever holds sales.payment.record without sales.order.view: the page would refuse them', function () {
        ['order' => $order, 'number' => $number] = paymentEventsReady();
        paymentEventsTransfer($order);
        $paymentOnly = Fx::staffWith(['sales.payment.record'], ['sa']);
        $both = Fx::staffWith(['sales.payment.record', 'sales.order.view'], ['sa']);
        DB::table('access.staff_notification_preferences')->insert([
            ['staff_user_id' => $paymentOnly, 'topic' => StaffNotificationTopic::PaymentsToCheck->value, 'panel' => true, 'email' => true],
            ['staff_user_id' => $both, 'topic' => StaffNotificationTopic::PaymentsToCheck->value, 'panel' => true, 'email' => true],
        ]);
        cache()->flush();

        paymentEventsAt('+72 hours');
        app(TransferOverdueCheck::class)->run();
        app(OutboxRelay::class)->run();

        $bell = static fn (string $staff) => DB::table('ops.inbox_entries')->where('kind', 'transfer_overdue')->where('staff_id', $staff)->sole();
        $email = static fn (string $staff) => CapturedEmails::installed()->for((string) DB::table('access.staff_users')->where('id', $staff)->value('email'));

        expect($bell($paymentOnly)->link)->toBeNull()
            ->and($bell($paymentOnly)->title_en)->toBe("Transfer to check for order {$number}")
            ->and($email($paymentOnly)->text)->toContain("order {$number}")->not->toContain('/admin/orders/')
            ->and($email($paymentOnly)->html)->not->toContain('/admin/orders/')
            ->and($bell($both)->link)->toBe('/admin/orders/'.$order)
            ->and($email($both)->text)->toContain('/admin/orders/'.$order);
    });

    it('alerts no one for a test order, for Sales never tells of one', function () {
        ['order' => $order] = paymentEventsReady();
        paymentEventsTransfer($order);
        DB::table('sales.orders')->where('id', $order)->update(['is_test' => true]);
        Fx::staffWith(['sales.payment.record'], ['sa']);

        paymentEventsAt('+73 hours');
        app(TransferOverdueCheck::class)->run();
        app(OutboxRelay::class)->run();

        expect(DB::table('public.outbox_messages')->where('type', TransferOverdue::class)->count())->toBe(0)
            ->and(paymentEventsBell('transfer_overdue'))->toBe([]);
    });

    it('alerts no one when the order is gone, ended since, or of another store than the event', function (Closure $event) {
        ['order' => $order] = paymentEventsReady();
        Fx::staffWith(['sales.payment.record'], ['sa', 'eg']);

        event($event($order));

        expect(paymentEventsBell('transfer_overdue'))->toBe([])
            ->and(CapturedEmails::installed()->sent)->toBe([]);
    })->with([
        'an order gone' => [fn (string $order) => new TransferOverdue((string) Str::uuid(), strtolower((string) Str::ulid()), Fx::storeId('sa'), CarbonImmutable::now())],
        'another store\'s' => [fn (string $order) => new TransferOverdue((string) Str::uuid(), $order, Fx::storeId('eg'), CarbonImmutable::now())],
        'cancelled since' => [function (string $order): TransferOverdue {
            Cx::asSuperAdmin(static fn () => app(CancelOrderHandler::class)->handle(new CancelOrder($order, 'Out of stock')));

            return new TransferOverdue((string) Str::uuid(), $order, Fx::storeId('sa'), CarbonImmutable::now());
        }],
        'came back since' => [function (string $order): TransferOverdue {
            paymentEventsCameBack($order);

            return new TransferOverdue((string) Str::uuid(), $order, Fx::storeId('sa'), CarbonImmutable::now());
        }],
    ]);
});

describe('the daily report\'s transfers overdue (§1.11)', function () {
    it('counts what is overdue against orders made by hand: a test order, one paid, one cancelled and a younger receipt left out', function () {
        ['overdue' => $overdue, 'younger' => $younger] = paymentEventsOverdueDay();
        $owed = paymentEventsDue($overdue);
        $now = CarbonImmutable::now()->toDateTimeImmutable();

        $section = app(DailyReportReader::class)->store(paymentEventsStore('sa'), $now, null);
        $text = paymentEventsReportText(new DailyReport($now, 'Asia/Riyadh', false, [$section], []));

        expect([$section->transfersOverdue->orders, $section->transfersOverdue->owed->minorUnits, $section->transfersOverdue->owed->currencyCode])->toBe([1, $owed, 'SAR'])
            // Overdue is part of what waits: this one, the younger receipt, and the one with no receipt yet.
            ->and($section->transfersWaiting->orders)->toBe(3)
            ->and($text)->toContain('Transfers overdue Orders: 1 , owed '.paymentEventsMoney($owed, 'en'));

        // An hour on, the younger one has its 72 hours too; the mark Sales keeps plays no part.
        paymentEventsAt('+73 hours');
        $later = app(DailyReportReader::class)->store(paymentEventsStore('sa'), CarbonImmutable::now()->toDateTimeImmutable(), null)->transfersOverdue;
        expect([$later->orders, $later->owed->minorUnits])->toBe([2, $owed + paymentEventsDue($younger)]);
    });

    it('reads the store\'s own check time, and writes the figure in the real PDF, in both languages', function () {
        ['overdue' => $overdue] = paymentEventsOverdueDay();
        Cx::setting('payments.transfer.check_hours', 100);
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $none = app(DailyReportReader::class)->store(paymentEventsStore('sa'), $now, null);
        Cx::setting('payments.transfer.check_hours', 72);
        $section = app(DailyReportReader::class)->store(paymentEventsStore('sa'), $now, null);
        $daily = new DailyReport($now, 'Asia/Riyadh', false, [$section], []);
        $pdf = static fn (string $locale): string => (string) preg_replace('/\s+/', ' ', PdfText::of(app(DailyReportRenderer::class)->render($daily, $locale)));

        expect($none->transfersOverdue->orders)->toBe(0)
            ->and($pdf('en'))->toContain('Transfers overdue')->toContain('Orders: 1, owed')
            ->and(paymentEventsReportText($daily, 'ar'))->toContain('تحويلات متأخرة عدد الطلبات 1 ، المستحق '.paymentEventsMoney(paymentEventsDue($overdue), 'ar'));
    });

    it('labels it as current on a report read after its day, in both languages', function (string $locale, string $today, string $past) {
        paymentEventsOverdueDay();
        $store = paymentEventsStore('sa');
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $yesterday = CarbonImmutable::now()->subDay()->setTimezone($store->timezone)->format('Y-m-d');
        $text = static fn (?string $day): string => paymentEventsReportText(new DailyReport($now, 'Asia/Riyadh', false, [app(DailyReportReader::class)->store($store, $now, $day)], []), $locale);

        expect($text(null))->toContain($today)
            ->and($text(null))->not->toContain($past)
            ->and($text($yesterday))->toContain($past);
    })->with([
        'English' => ['en', 'Transfers overdue', 'Transfers overdue (as of now, not that day)'],
        'Arabic' => ['ar', 'تحويلات متأخرة', 'تحويلات متأخرة (حتى الآن، لا في ذلك اليوم)'],
    ]);

    it('labels it as current in the totals too, on a report read after its day', function () {
        ['overdue' => $overdue] = paymentEventsOverdueDay();
        $yesterday = CarbonImmutable::now()->subDay()->setTimezone('Asia/Riyadh')->format('Y-m-d');

        $report = app(DailyReportReader::class)->report(app(DailyReportPeople::class)->everyStore(), CarbonImmutable::now()->toDateTimeImmutable(), $yesterday, 'Asia/Riyadh');

        // The store's section and the totals; nowhere the plain label.
        expect(substr_count(paymentEventsReportText($report), 'Transfers overdue (as of now, not that day) Orders: 1 , owed '.paymentEventsMoney(paymentEventsDue($overdue), 'en')))->toBe(2)
            ->and(paymentEventsReportText($report))->not->toContain('Transfers overdue Orders:');
    });

    it('adds it up in the totals per currency, never converted', function () {
        ['overdue' => $overdue] = paymentEventsOverdueDay();
        $owed = paymentEventsDue($overdue);

        $report = app(DailyReportReader::class)->report(app(DailyReportPeople::class)->everyStore(), CarbonImmutable::now()->toDateTimeImmutable(), null, 'Asia/Riyadh');
        $totals = [];
        foreach ($report->totals as $total) {
            $totals[$total->currencyCode] = [$total->overdueOrders, $total->overdueOwed->minorUnits];
        }

        expect($totals)->toBe(['SAR' => [1, $owed], 'EGP' => [0, 0], 'AED' => [0, 0]])
            ->and(substr_count(paymentEventsReportText($report), 'Transfers overdue Orders: 1 , owed '.paymentEventsMoney($owed, 'en')))->toBe(2);
    });
});
