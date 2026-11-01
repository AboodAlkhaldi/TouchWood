<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\StaffAlertRecipientDto;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\B2B\Public\Events\CompanyApplicationSubmitted;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Feedback\Public\Contracts\FeedbackApi;
use Modules\Feedback\Public\Dto\QuestionForMessageDto;
use Modules\Feedback\Public\Dto\ReviewForMessageDto;
use Modules\Feedback\Public\Enums\QuestionStatus;
use Modules\Feedback\Public\Enums\ReviewStatus;
use Modules\Feedback\Public\Events\QuestionAnswered;
use Modules\Feedback\Public\Events\QuestionNotPublished;
use Modules\Feedback\Public\Events\ReviewNotPublished;
use Modules\Inventory\Public\Events\LowStockChanged;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Dto\ExpiringPointsDto;
use Modules\Loyalty\Public\Dto\ExpiringPointsPageDto;
use Modules\Loyalty\Public\Dto\PointsEntryDto;
use Modules\Loyalty\Public\Enums\EntryKind;
use Modules\Loyalty\Public\Events\PointsChangedByHand;
use Modules\Loyalty\Public\Events\PointsEarned;
use Modules\Ops\Application\Alerts\EventDeliveries;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Application\Alerts\EventSender;
use Modules\Ops\Application\Alerts\StaffAlerts;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Domain\Model\InboxEntry;
use Modules\Ops\Domain\Model\SendStatus;
use Modules\Ops\Domain\Model\SentMessage;
use Modules\Ops\Domain\Repository\Inbox;
use Modules\Ops\Domain\Repository\SentMessages;
use Modules\Ops\Domain\ValueObject\InboxOwner;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Modules\Ops\Infrastructure\Listener\AlertStaff;
use Modules\Ops\Infrastructure\Listener\TellCustomersAboutCoupon;
use Modules\Ops\Infrastructure\Queue\RemindExpiringPointsJob;
use Modules\Ops\Infrastructure\Queue\SendEventEmailJob;
use Modules\Ops\Infrastructure\Queue\SendEventSmsJob;
use Modules\Ops\Public\Enums\MessageChannel;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Events\CouponAssigned;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\RecordPayment\RecordPayment;
use Modules\Sales\Application\Command\RecordPayment\RecordPaymentHandler;
use Modules\Sales\Application\Command\RecordRefund\RecordRefund;
use Modules\Sales\Application\Command\RecordRefund\RecordRefundHandler;
use Modules\Sales\Application\Command\VoidMoneyRecord\VoidMoneyRecord;
use Modules\Sales\Application\Command\VoidMoneyRecord\VoidMoneyRecordHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Dto\MoneyRecordMessageDto;
use Modules\Sales\Public\Dto\OrderMessageDto;
use Modules\Sales\Public\Dto\OrderMessageLineDto;
use Modules\Sales\Public\Dto\ReturnMessageDto;
use Modules\Sales\Public\Events\OrderApproved;
use Modules\Sales\Public\Events\OrderCameBack;
use Modules\Sales\Public\Events\OrderCancelled;
use Modules\Sales\Public\Events\OrderDelivered;
use Modules\Sales\Public\Events\OrderEdited;
use Modules\Sales\Public\Events\OrderPlaced;
use Modules\Sales\Public\Events\OrderRejected;
use Modules\Sales\Public\Events\OrderShipped;
use Modules\Sales\Public\Events\PaymentRecorded;
use Modules\Sales\Public\Events\RefundRecorded;
use Modules\Sales\Public\Events\ReturnAccepted;
use Modules\Sales\Public\Events\ReturnCompleted;
use Modules\Sales\Public\Events\ReturnReceived;
use Modules\Sales\Public\Events\ReturnRefused;
use Modules\Sales\Public\Events\ReturnRequested;
use Modules\Sync\Public\Events\SyncNeedsAttention;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\RecordingSmsSenders;
use Tests\Modules\Ops\Support\WrittenFixtures as Wx;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Ops steps 5 and 6 (ops.md §1.4-§1.6, §6, §8): every moment another module publishes reaches the right
| person - the customer of the event, the staff of the event's store who hold the topic's permission -
| by the kind's channels and each staff member's switches, in their language, once per event.
|
| Access, Platform and Ops are real. The other modules' reads for messages (Sales, Loyalty, Feedback,
| Catalog) are stand-ins answering what each read's contract says: their own tests prove the reads; these
| prove what Ops does with the answers. The queue is `sync`: an event's emails have gone when event()
| returns.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    RecordingSecurityMessages::install();
    CapturedEmails::install();
    eventMessagesSales(null);
});

/** Sales' read for messages, answering this order for any id, this return and this money record. */
function eventMessagesSales(?OrderMessageDto $order, ?ReturnMessageDto $return = null, ?MoneyRecordMessageDto $money = null): void
{
    $sales = Mockery::mock(SalesReports::class);
    $sales->allows('orderForMessage')->andReturnUsing(static fn (string $id, string $locale): ?OrderMessageDto => $order === null ? null : new OrderMessageDto(
        $id, $order->number, $order->storeId, $order->customerId, $order->locale, $order->status, $order->shipmentStatus, $order->wayToPay,
        $locale === 'ar' ? [new OrderMessageLineDto('كرسي', [['attribute' => 'اللون', 'value' => 'بني']], 2, Money::of(20000, 'SAR'), false)] : $order->lines,
        $order->subtotal, $order->couponDiscount, $order->pointsDiscount, $order->shipping, $order->vat, $order->total, $order->carrier, $order->trackingNumber, $order->rejectionReason, $order->cancelReason,
        $order->recipientName, $order->deliveryAddress,
    ));
    $sales->allows('returnForMessage')->andReturn($return);
    $sales->allows('moneyRecordForMessage')->andReturn($money);
    app()->instance(SalesReports::class, $sales);
}

function eventMessagesOrder(string $customerId, string $storeCode = 'sa', ?string $rejection = null, ?string $carrier = null, ?string $tracking = null, int $coupon = 0, string $status = 'APPROVED', string $address = 'King Fahd Road 12, Riyadh'): OrderMessageDto
{
    return new OrderMessageDto(
        strtolower((string) Str::ulid()), 'SA-0007', Fx::storeId($storeCode), $customerId, 'en', $status, 'NOT_SHIPPED', 'BANK_TRANSFER',
        [new OrderMessageLineDto('Chair', [['attribute' => 'Colour', 'value' => 'Brown']], 2, Money::of(20000, 'SAR'), false)],
        Money::of(20000, 'SAR'), Money::of($coupon, 'SAR'), Money::of(0, 'SAR'), Money::of(2500, 'SAR'), Money::of(3375, 'SAR'), Money::of(25875 - $coupon, 'SAR'),
        $carrier, $tracking, $rejection, null, 'Sara Ali', $address,
    );
}

/** A payment or refund of 150.00, as Sales' read gives it. */
function eventMessagesMoney(string $customerId, string $kind = 'REFUND', string $way = 'BANK_TRANSFER', bool $voided = false): MoneyRecordMessageDto
{
    return new MoneyRecordMessageDto(strtolower((string) Str::ulid()), $kind, strtolower((string) Str::ulid()), 'SA-0007', Fx::storeId('sa'), $customerId, Money::of(15000, 'SAR'), $way, '2026-11-01', $voided);
}

/**
 * Promotions' reads for C15: this code, and these pages of customers by the cursor they follow ('' for the first).
 *
 * @param  array<string, list<string>>  $pages
 * @param  ArrayObject<int, string|null>|null  $asked  each cursor asked for, in order
 */
function eventMessagesPromotions(?string $code, array $pages, ?ArrayObject $asked = null): void
{
    $promotions = Mockery::mock(PromotionsApi::class);
    $promotions->allows('couponCode')->andReturn($code);
    $promotions->allows('assignedCustomers')->andReturnUsing(static function (string $id, ?string $after, int $limit) use ($pages, $asked): array {
        $asked?->append($after);

        return $pages[$after ?? ''] ?? [];
    });
    app()->instance(PromotionsApi::class, $promotions);
}

/** A message's row, written QUEUED as a listener writes it; its id. */
function eventMessagesQueued(EventMessage $message, MessageChannel $channel, ?string $customerId, ?string $staffId): string
{
    $id = strtolower((string) Str::ulid());
    app(SentMessages::class)->record(new SentMessage($id, $message->kind, $customerId, $staffId, StoreId::fromString($message->storeId), $channel, null, SendStatus::Queued, CarbonImmutable::now()->toDateTimeImmutable()));

    return $id;
}

/**
 * The critical SMS to one staff member, queued and run as its job runs it. Its alert's permission here is
 * one that is declared today (`payments.gateway.manage` is stage 7's): super admins hold every permission.
 */
function eventMessagesSms(EventMessage $message, string $staffId): void
{
    app(EventSender::class)->smsStaff($message, $staffId, StaffNotificationTopic::PaymentProblems, 'sales.order.view', eventMessagesQueued($message, MessageChannel::Sms, null, $staffId));
}

function eventMessagesId(): string
{
    return (string) Str::uuid();
}

/** @return list<object{kind: string, title_en: string, title_ar: string, line_en: string, link: ?string, store_id: string}> */
function eventMessagesBell(?string $customerId = null, ?string $staffId = null): array
{
    /** @var list<object{kind: string, title_en: string, title_ar: string, line_en: string, link: ?string, store_id: string}> */
    return DB::table('ops.inbox_entries')
        ->when($customerId !== null, fn ($query) => $query->where('customer_id', strtolower((string) $customerId)))
        ->when($staffId !== null, fn ($query) => $query->where('staff_id', strtolower((string) $staffId)))
        ->orderBy('created_at')->get()->all();
}

/** @return list<string> "KIND CHANNEL STATUS" */
function eventMessagesSent(?string $customerId = null, ?string $staffId = null): array
{
    return array_values(DB::table('ops.sent_messages')
        ->when($customerId !== null, fn ($query) => $query->where('customer_id', strtolower((string) $customerId)))
        ->when($staffId !== null, fn ($query) => $query->where('staff_id', strtolower((string) $staffId)))
        ->orderBy('created_at')->get()
        ->map(static fn (object $row): string => "{$row->kind} {$row->channel} {$row->status}")->all());
}

function eventMessagesSwitch(string $staffId, StaffNotificationTopic $topic, bool $panel, bool $email): void
{
    DB::table('access.staff_notification_preferences')->insert(['staff_user_id' => $staffId, 'topic' => $topic->value, 'panel' => $panel, 'email' => $email]);
    cache()->flush();
}

function eventMessagesEmailOf(string $staffId): string
{
    return (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');
}

describe('messages to customers (§1.5)', function () {
    it('tells the customer an order is approved: a bell entry in the store, one email in their language, recorded - once per event', function () {
        $sara = Fx::customer('sara@example.test');
        eventMessagesSales($order = eventMessagesOrder($sara));
        $event = new OrderApproved(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now());

        event($event);
        event($event);

        $bell = eventMessagesBell($sara);
        $email = CapturedEmails::installed()->last();

        expect($bell)->toHaveCount(1)
            ->and([$bell[0]->kind, $bell[0]->title_en, $bell[0]->title_ar, $bell[0]->link, $bell[0]->store_id])
            ->toBe(['order_approved', 'Order SA-0007 approved', 'اعتُمد الطلب SA-0007', '/sa/en/account/orders/SA-0007', Fx::storeId('sa')])
            ->and(CapturedEmails::installed()->to())->toBe(['sara@example.test'])
            ->and($email->subject)->toBe('Your order SA-0007 is approved')
            ->and($email->fromName)->toBe('Saudi Arabia')
            ->and($email->text)->toContain('Hello Sara,')->toContain('/sa/en/account/orders/SA-0007')
            ->and($email->html)->toContain('icons/email-logo.png')
            ->and(eventMessagesSent($sara))->toBe(['order_approved EMAIL SENT']);
    });

    it('writes to an Arabic customer in Arabic, the bell opening their Arabic page', function () {
        $sara = Fx::customer('sara@example.test');
        Wx::set($sara, ['locale' => 'ar']);
        eventMessagesSales($order = eventMessagesOrder($sara));

        event(new OrderPlaced(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

        $email = CapturedEmails::installed()->for('sara@example.test');
        expect($email->subject)->toBe('استلمنا طلبك SA-0007')
            ->and($email->fromName)->toBe('السعودية')
            ->and($email->text)->toContain('مرحبًا Sara،')->toContain('كرسي (بني) x 2')->toContain('تحويل بنكي')
            ->and(eventMessagesBell($sara)[0]->link)->toBe('/sa/ar/account/orders/SA-0007');
    });

    it('puts the order\'s lines, totals and way to pay in the order-taken email; a coupon row only when one took something off', function () {
        $sara = Fx::customer('sara@example.test');
        eventMessagesSales($order = eventMessagesOrder($sara));
        event(new OrderPlaced(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        $plain = CapturedEmails::installed()->last()->text;

        eventMessagesSales($couponed = eventMessagesOrder($sara, coupon: 1000));
        event(new OrderEdited(eventMessagesId(), strtolower((string) Str::ulid()), $couponed->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        $changed = CapturedEmails::installed()->last();

        expect($plain)->toContain('Chair (Brown) x 2: 200.00 SAR')->toContain('Subtotal: 200.00 SAR')->toContain('Shipping: 25.00 SAR')
            ->toContain('VAT: 33.75 SAR')->toContain('Total: 258.75 SAR')->toContain('Payment: Bank transfer')
            ->and(str_contains($plain, 'Coupon'))->toBeFalse()
            ->and($changed->subject)->toBe('Your order SA-0007 was changed')
            ->and($changed->text)->toContain('New total: 248.75 SAR')->toContain('Coupon: -10.00 SAR');
    });

    it('adds staff\'s reason to a rejection only when they gave one, and the carrier and tracking number to a shipment', function () {
        $sara = Fx::customer('sara@example.test');
        eventMessagesSales($order = eventMessagesOrder($sara, rejection: 'Out of stock'));
        event(new OrderRejected(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        $withReason = CapturedEmails::installed()->last()->text;

        eventMessagesSales($order = eventMessagesOrder($sara));
        event(new OrderRejected(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        $without = CapturedEmails::installed()->last()->text;

        eventMessagesSales($order = eventMessagesOrder($sara, carrier: 'SMSA', tracking: 'TRK123'));
        event(new OrderShipped(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        $shipped = CapturedEmails::installed()->last()->text;

        expect($withReason)->toContain('Reason: Out of stock')
            ->and(str_contains($without, 'Reason:'))->toBeFalse()
            ->and($shipped)->toContain('has been shipped with SMSA')->toContain('Tracking number: TRK123')
            ->and(str_contains($shipped, 'Track your order'))->toBeFalse();
    });

    it('tells the store\'s return window on delivery, and a cancellation, consent or not: service messages ignore it', function () {
        $sara = Fx::customer('sara@example.test');
        Wx::set($sara, ['marketing_consent' => false]);
        eventMessagesSales($order = eventMessagesOrder($sara));

        event(new OrderDelivered(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        event(new OrderCancelled(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

        expect(CapturedEmails::installed()->sent[0]->text)->toContain('within 14 days of delivery')
            ->and(CapturedEmails::installed()->sent[1]->subject)->toBe('Your order SA-0007 is cancelled')
            ->and(eventMessagesSent($sara))->toEqualCanonicalizing(['order_delivered EMAIL SENT', 'order_cancelled EMAIL SENT']);
    });

    it('sends nothing to an anonymized account, nor for an order Sales no longer knows', function () {
        $sara = Fx::customer('sara@example.test');
        eventMessagesSales(null);
        event(new OrderApproved(eventMessagesId(), strtolower((string) Str::ulid()), Fx::storeId('sa'), $sara, CarbonImmutable::now()));

        eventMessagesSales($order = eventMessagesOrder($sara));
        Wx::set($sara, ['anonymized_at' => CarbonImmutable::now()->toIso8601String()]);
        event(new OrderApproved(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

        expect(eventMessagesBell($sara))->toBe([])->and(CapturedEmails::installed()->sent)->toBe([]);
    });

    it('shows where the order goes in the order-taken email only, in both languages: escaped, its line breaks kept, nothing in the bell', function () {
        $sara = Fx::customer('sara@example.test');
        $omar = Fx::customer('omar@example.test');
        Wx::set($omar, ['locale' => 'ar']);
        eventMessagesSales($order = eventMessagesOrder($sara, address: "King Fahd Road 12\nRiyadh <b>north</b>"));
        event(new OrderPlaced(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        $english = CapturedEmails::installed()->for('sara@example.test');
        eventMessagesSales($theirs = eventMessagesOrder($omar, address: 'طريق الملك فهد 12'));
        event(new OrderPlaced(eventMessagesId(), $theirs->orderId, Fx::storeId('sa'), $omar, CarbonImmutable::now()));
        $arabic = CapturedEmails::installed()->for('omar@example.test');
        $bell = eventMessagesBell($sara)[0];

        expect($english->text)->toContain("Delivery to:\nSara Ali\nKing Fahd Road 12\nRiyadh <b>north</b>")
            ->and($english->html)->toContain('King Fahd Road 12<br />')->toContain('&lt;b&gt;north&lt;/b&gt;')
            ->and(str_contains($english->html, '<b>'))->toBeFalse()
            ->and($arabic->text)->toContain("التوصيل إلى:\nSara Ali\nطريق الملك فهد 12")
            ->and([$bell->title_en, $bell->line_en])->each->not->toContain('King Fahd');
    });

    it('has no address line for an order with none, and none in the order-changed email', function () {
        $sara = Fx::customer('sara@example.test');
        eventMessagesSales($order = eventMessagesOrder($sara, address: ''));
        event(new OrderPlaced(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        $placed = CapturedEmails::installed()->last()->text;

        eventMessagesSales($edited = eventMessagesOrder($sara));
        event(new OrderEdited(eventMessagesId(), strtolower((string) Str::ulid()), $edited->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

        expect(str_contains($placed, 'Delivery to'))->toBeFalse()
            ->and(str_contains(CapturedEmails::installed()->last()->text, 'Delivery to'))->toBeFalse();
    });

    it('tells the customer a refund was sent, the amount and the way - once per event', function () {
        $sara = Fx::customer('sara@example.test');
        eventMessagesSales(null, null, $record = eventMessagesMoney($sara));
        $event = new RefundRecorded(eventMessagesId(), $record->moneyRecordId, $record->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now());

        event($event);
        event($event);

        $bell = eventMessagesBell($sara);
        $email = CapturedEmails::installed()->last();

        expect($bell)->toHaveCount(1)
            ->and([$bell[0]->kind, $bell[0]->title_en, $bell[0]->line_en, $bell[0]->link, $bell[0]->store_id])
            ->toBe(['refund_sent', 'Refund sent for order SA-0007', 'We sent you 150.00 SAR by bank transfer.', '/sa/en/account/orders/SA-0007', Fx::storeId('sa')])
            ->and(CapturedEmails::installed()->to())->toBe(['sara@example.test'])
            ->and($email->subject)->toBe('Your refund for order SA-0007 is sent')
            ->and($email->text)->toContain('Hello Sara,')->toContain('We sent you a refund of 150.00 SAR for order SA-0007, by bank transfer.')
            ->and(eventMessagesSent($sara))->toBe(['refund_sent EMAIL SENT']);
    });

    it('writes the refund to an Arabic customer in Arabic, the way in words', function () {
        $sara = Fx::customer('sara@example.test');
        Wx::set($sara, ['locale' => 'ar']);
        eventMessagesSales(null, null, $record = eventMessagesMoney($sara, way: 'OTHER'));

        event(new RefundRecorded(eventMessagesId(), $record->moneyRecordId, $record->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

        expect(CapturedEmails::installed()->last()->subject)->toBe('أُرسل استرداد الطلب SA-0007')
            ->and(CapturedEmails::installed()->last()->text)->toContain('أرسلنا لك استردادًا بمبلغ 150.00 ر.س للطلب SA-0007 عبر طريقة أخرى.')
            ->and(eventMessagesBell($sara)[0]->link)->toBe('/sa/ar/account/orders/SA-0007');
    });

    it('tells the customer a payment was received, in their language - once per event', function () {
        $sara = Fx::customer('sara@example.test');
        $omar = Fx::customer('omar@example.test');
        Wx::set($omar, ['locale' => 'ar']);
        eventMessagesSales(null, null, $record = eventMessagesMoney($sara, 'PAYMENT'));
        $event = new PaymentRecorded(eventMessagesId(), $record->moneyRecordId, $record->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now());

        event($event);
        event($event);

        $bell = eventMessagesBell($sara);
        expect($bell)->toHaveCount(1)
            ->and([$bell[0]->kind, $bell[0]->title_en, $bell[0]->line_en, $bell[0]->link])->toBe(['payment_received', 'Payment received for order SA-0007', 'We received 150.00 SAR.', '/sa/en/account/orders/SA-0007'])
            ->and(CapturedEmails::installed()->to())->toBe(['sara@example.test'])
            ->and(CapturedEmails::installed()->last()->subject)->toBe('We received your payment for order SA-0007')
            ->and(CapturedEmails::installed()->last()->text)->toContain('We received your payment of 150.00 SAR for order SA-0007. Thank you.')
            ->and(eventMessagesSent($sara))->toBe(['payment_received EMAIL SENT']);

        eventMessagesSales(null, null, $theirs = eventMessagesMoney($omar, 'PAYMENT'));
        event(new PaymentRecorded(eventMessagesId(), $theirs->moneyRecordId, $theirs->orderId, Fx::storeId('sa'), $omar, CarbonImmutable::now()));

        expect(CapturedEmails::installed()->for('omar@example.test')->text)->toContain('استلمنا دفعتك بمبلغ 150.00 ر.س للطلب SA-0007. شكرًا لك.');
    });

    it('sends nothing for a money record that is voided, gone, of the other kind, or another customer\'s', function (string $case) {
        $sara = Fx::customer('sara@example.test');
        $record = match ($case) {
            'voided' => eventMessagesMoney($sara, voided: true),
            'gone' => null,
            'other kind' => eventMessagesMoney($sara, 'PAYMENT'),
            'other customer' => eventMessagesMoney(Fx::customer('omar@example.test')),
            default => throw new LogicException($case),
        };
        eventMessagesSales(null, null, $record);
        $id = strtolower((string) Str::ulid());

        event(new RefundRecorded(eventMessagesId(), $id, $id, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

        expect(eventMessagesBell($sara))->toBe([])->and(CapturedEmails::installed()->sent)->toBe([])->and(eventMessagesSent($sara))->toBe([]);
    })->with(['voided', 'gone', 'other kind', 'other customer']);

    it('sends nothing for a payment that is voided or gone', function (bool $voided) {
        $sara = Fx::customer('sara@example.test');
        eventMessagesSales(null, null, $voided ? eventMessagesMoney($sara, 'PAYMENT', voided: true) : null);
        $id = strtolower((string) Str::ulid());

        event(new PaymentRecorded(eventMessagesId(), $id, $id, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

        expect(eventMessagesBell($sara))->toBe([])->and(CapturedEmails::installed()->sent)->toBe([]);
    })->with([true, false]);

    it('sends no email for a record voided after its message was queued: its row is removed', function () {
        $sara = Fx::customer('sara@example.test');
        eventMessagesSales(null, null, eventMessagesMoney($sara, voided: true));
        $message = new EventMessage(MessageKind::RefundSent, Fx::storeId('sa'), ['moneyRecordId' => strtolower((string) Str::ulid())]);
        $id = eventMessagesQueued($message, MessageChannel::Email, $sara, null);

        app()->call([new SendEventEmailJob($message, $sara, null, $id), 'handle']);

        expect(eventMessagesSent($sara))->toBe([])->and(CapturedEmails::installed()->sent)->toBe([]);
    });

    it('writes C1, C9b and C10 from Sales\' own reads, and nothing once the record is voided', function () {
        seed(CatalogSeeder::class);
        app()->forgetInstance(SalesReports::class);
        $ready = Cx::ready();
        $placed = Cx::place(Cx::start($ready['address'])->id);
        $store = Fx::storeId('sa');
        $row = DB::table('sales.orders')->where('id', $placed->id)->first();
        /** @var array{recipientName: string, formatted: string} $address */
        $address = json_decode((string) $row?->address, true);
        $amount = number_format((int) $row?->due_minor / 100, 2, '.', '');
        $day = CarbonImmutable::now('Asia/Riyadh')->format('Y-m-d');
        $email = (string) DB::table('access.customers')->where('id', $ready['customer'])->value('email');
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::PAYMENT_RECORD], ['sa']));

        event(new OrderPlaced(eventMessagesId(), $placed->id, $store, $ready['customer'], CarbonImmutable::now()));
        expect(CapturedEmails::installed()->last()->toEmail)->toBe($email)
            ->and($address['formatted'])->not->toBe('')
            ->and(CapturedEmails::installed()->last()->text)->toContain("Delivery to:\n{$address['recipientName']}\n{$address['formatted']}");

        app(ApproveOrderHandler::class)->handle(new ApproveOrder($placed->id));
        $paid = app(RecordPaymentHandler::class)->handle(new RecordPayment($placed->id, 'BANK_TRANSFER', 'T-1', null, $day, (string) Str::uuid()));
        event(new PaymentRecorded(eventMessagesId(), $paid, $placed->id, $store, $ready['customer'], CarbonImmutable::now()));
        expect(CapturedEmails::installed()->last()->text)->toContain("We received your payment of {$amount} SAR for order {$placed->number}.");

        $refund = app(RecordRefundHandler::class)->handle(new RecordRefund($placed->id, '100.00', 'BANK_TRANSFER', 'T-2', null, $day, (string) Str::uuid()));
        event(new RefundRecorded(eventMessagesId(), $refund, $placed->id, $store, $ready['customer'], CarbonImmutable::now()));
        expect(CapturedEmails::installed()->last()->text)->toContain("We sent you a refund of 100.00 SAR for order {$placed->number}, by bank transfer.");

        $sent = count(CapturedEmails::installed()->sent);
        app(VoidMoneyRecordHandler::class)->handle(new VoidMoneyRecord('REFUND', $refund, 'Recorded by mistake'));
        event(new RefundRecorded(eventMessagesId(), $refund, $placed->id, $store, $ready['customer'], CarbonImmutable::now()));
        expect(CapturedEmails::installed()->sent)->toHaveCount($sent);
    });

    it('tells each customer the assignment added their coupon\'s code, in their language, consent or not - once per event', function () {
        $sara = Fx::customer('sara@example.test');
        $omar = Fx::customer('omar@example.test');
        Wx::set($sara, ['marketing_consent' => false]);
        Wx::set($omar, ['locale' => 'ar']);
        eventMessagesPromotions('SAVE10', ['' => [$sara, $omar]]);
        $event = new CouponAssigned(eventMessagesId(), strtolower((string) Str::ulid()), strtolower((string) Str::ulid()), Fx::storeId('sa'), CarbonImmutable::now());

        event($event);
        event($event);

        $bell = eventMessagesBell($sara);
        $email = CapturedEmails::installed()->for('sara@example.test');

        expect($bell)->toHaveCount(1)
            ->and([$bell[0]->kind, $bell[0]->title_en, $bell[0]->line_en, $bell[0]->link, $bell[0]->store_id])
            ->toBe(['coupon_assigned', 'A coupon for you', 'Use the code SAVE10 at checkout.', '/sa/en/account/coupons', Fx::storeId('sa')])
            ->and(CapturedEmails::installed()->sent)->toHaveCount(2)
            ->and($email->subject)->toBe('A coupon for you from Saudi Arabia')
            ->and($email->text)->toContain('Hello Sara,')->toContain('You have a new coupon. Use the code SAVE10 at checkout.')
            ->and(CapturedEmails::installed()->for('omar@example.test')->text)->toContain('لديك قسيمة جديدة. استخدم الرمز SAVE10 عند إتمام الطلب.')
            ->and(eventMessagesBell($omar)[0]->link)->toBe('/sa/ar/account/coupons')
            ->and(eventMessagesSent($sara))->toBe(['coupon_assigned EMAIL SENT'])
            ->and(eventMessagesSent($omar))->toBe(['coupon_assigned EMAIL SENT']);
    });

    it('pages through the customers an assignment added', function () {
        $sara = Fx::customer('sara@example.test');
        $omar = Fx::customer('omar@example.test');
        // A full page: Sara and ids of no one, which Access leaves out.
        $first = [$sara];
        while (count($first) < TellCustomersAboutCoupon::PAGE) {
            $first[] = strtolower((string) Str::ulid());
        }
        $after = $first[count($first) - 1];
        $asked = new ArrayObject;
        eventMessagesPromotions('SAVE10', ['' => $first, $after => [$omar]], $asked);

        event(new CouponAssigned(eventMessagesId(), strtolower((string) Str::ulid()), strtolower((string) Str::ulid()), Fx::storeId('sa'), CarbonImmutable::now()));

        expect($asked->getArrayCopy())->toBe([null, $after])
            ->and(CapturedEmails::installed()->to())->toEqualCanonicalizing(['sara@example.test', 'omar@example.test']);
    });

    it('carries on from where a failure stopped: a customer already told is not told again', function () {
        $sara = Fx::customer('sara@example.test');
        $omar = Fx::customer('omar@example.test');
        eventMessagesPromotions('SAVE10', ['' => [$sara, $omar]]);
        $event = new CouponAssigned(eventMessagesId(), strtolower((string) Str::ulid()), strtolower((string) Str::ulid()), Fx::storeId('sa'), CarbonImmutable::now());
        app(HandledEvents::class)->once(TellCustomersAboutCoupon::onceId($event->eventId, $sara), TellCustomersAboutCoupon::class, static fn () => null);

        event($event);

        expect(CapturedEmails::installed()->to())->toBe(['omar@example.test'])->and(eventMessagesBell($sara))->toBe([]);
    });

    it('sends nothing for a coupon deleted since, and nothing to an anonymized account', function () {
        $sara = Fx::customer('sara@example.test');
        $omar = Fx::customer('omar@example.test');
        Wx::set($omar, ['anonymized_at' => CarbonImmutable::now()->toIso8601String()]);
        eventMessagesPromotions(null, ['' => [$sara]]);
        $id = static fn (): string => strtolower((string) Str::ulid());
        event(new CouponAssigned(eventMessagesId(), $id(), $id(), Fx::storeId('sa'), CarbonImmutable::now()));

        expect(eventMessagesBell($sara))->toBe([])->and(eventMessagesSent($sara))->toBe([])->and(CapturedEmails::installed()->sent)->toBe([]);

        eventMessagesPromotions('SAVE10', ['' => [$omar]]);
        event(new CouponAssigned(eventMessagesId(), $id(), $id(), Fx::storeId('sa'), CarbonImmutable::now()));

        expect(eventMessagesBell($omar))->toBe([])->and(CapturedEmails::installed()->sent)->toBe([]);
    });

    it('puts the bell entry in the event\'s store only: the customer\'s bell in another store shows nothing', function () {
        $sara = Fx::customer('sara@example.test');
        eventMessagesSales($order = eventMessagesOrder($sara, 'ae'));

        event(new OrderApproved(eventMessagesId(), $order->orderId, Fx::storeId('ae'), $sara, CarbonImmutable::now()));

        expect(array_map(static fn (object $entry): string => $entry->store_id, eventMessagesBell($sara)))->toBe([Fx::storeId('ae')])
            ->and(eventMessagesBell($sara)[0]->link)->toBe('/ae/en/account/orders/SA-0007')
            ->and(CapturedEmails::installed()->last()->fromName)->toBe('United Arab Emirates');
    });

    it('tells a refused return with the store\'s words, and a completed one with the refund owed', function () {
        $sara = Fx::customer('sara@example.test');
        $returnId = strtolower((string) Str::ulid());
        eventMessagesSales(null, new ReturnMessageDto($returnId, strtolower((string) Str::ulid()), 'SA-0007', Fx::storeId('sa'), $sara, 'REFUSED', 'Used item', null));
        event(new ReturnRefused(eventMessagesId(), $returnId, strtolower((string) Str::ulid()), Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        $refused = CapturedEmails::installed()->last()->text;

        eventMessagesSales(null, new ReturnMessageDto($returnId, strtolower((string) Str::ulid()), 'SA-0007', Fx::storeId('sa'), $sara, 'COMPLETED', null, Money::of(15000, 'SAR')));
        event(new ReturnCompleted(eventMessagesId(), $returnId, strtolower((string) Str::ulid()), Fx::storeId('sa'), $sara, CarbonImmutable::now()));

        expect($refused)->toContain('Reason: Used item')
            ->and(CapturedEmails::installed()->last()->text)->toContain('A refund of 150.00 SAR is owed to you.')
            ->and(eventMessagesBell($sara)[1]->link)->toBe("/sa/en/account/returns/{$returnId}");
    });

    it('tells points earned, and points changed by hand signed, with the admin\'s reason', function () {
        $sara = Fx::customer('sara@example.test');
        $loyalty = Mockery::mock(LoyaltyApi::class);
        $loyalty->allows('entryForMessage')->with(1)->andReturn(new PointsEntryDto(1, Fx::storeId('sa'), $sara, EntryKind::Earned, 120, null, 'SA-0007', null, 120));
        $loyalty->allows('entryForMessage')->with(2)->andReturn(new PointsEntryDto(2, Fx::storeId('sa'), $sara, EntryKind::Deducted, -20, 'Correction', null, null, 100));
        app()->instance(LoyaltyApi::class, $loyalty);

        event(new PointsEarned(eventMessagesId(), 1, strtolower((string) Str::ulid()), Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        event(new PointsChangedByHand(eventMessagesId(), 2, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

        expect(CapturedEmails::installed()->sent[0]->text)->toContain('You earned 120 points for order SA-0007.')
            ->and(CapturedEmails::installed()->sent[1]->text)->toContain('by -20.')->toContain('Reason: Correction')
            ->and(eventMessagesBell($sara)[0]->link)->toBe('/sa/en/account/points');
    });

    it('tells an answer only while the question is still answered, and "not published" only with a reason, while it still stands', function () {
        $sara = Fx::customer('sara@example.test');
        $product = strtolower((string) Str::ulid());
        $catalog = Mockery::mock(CatalogApi::class);
        $catalog->allows('product')->andReturn(new ProductDto($product, 'كرسي', 'Oak chair', ProductStage::Ready, strtolower((string) Str::ulid()), null, null));
        app()->instance(CatalogApi::class, $catalog);
        $feedback = Mockery::mock(FeedbackApi::class);
        $question = static fn (string $id, QuestionStatus $status, ?string $reason): QuestionForMessageDto => new QuestionForMessageDto($id, $product, Fx::storeId('sa'), $sara, $status, 'Is it oak?', 'Yes', 'en', $reason);
        $feedback->allows('questionForMessage')->with('q-answered')->andReturn($question('q-answered', QuestionStatus::Answered, null));
        $feedback->allows('questionForMessage')->with('q-taken-down')->andReturn($question('q-taken-down', QuestionStatus::Unpublished, null));
        $feedback->allows('questionForMessage')->with('q-rejected')->andReturn($question('q-rejected', QuestionStatus::Rejected, 'Off topic'));
        $feedback->allows('questionForMessage')->with('q-no-reason')->andReturn($question('q-no-reason', QuestionStatus::Rejected, null));
        $feedback->allows('reviewForMessage')->with('r-rejected')->andReturn(new ReviewForMessageDto('r-rejected', $product, strtolower((string) Str::ulid()), Fx::storeId('sa'), $sara, ReviewStatus::Rejected, 2, 'en', 'Rude words'));
        $feedback->allows('reviewForMessage')->with('r-approved-again')->andReturn(new ReviewForMessageDto('r-approved-again', $product, strtolower((string) Str::ulid()), Fx::storeId('sa'), $sara, ReviewStatus::Approved, 2, 'en', 'Rude words'));
        app()->instance(FeedbackApi::class, $feedback);

        foreach (['q-answered', 'q-taken-down'] as $id) {
            event(new QuestionAnswered(eventMessagesId(), $id, $product, Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        }

        foreach (['q-rejected', 'q-no-reason'] as $id) {
            event(new QuestionNotPublished(eventMessagesId(), $id, $product, Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        }

        foreach (['r-rejected', 'r-approved-again'] as $id) {
            event(new ReviewNotPublished(eventMessagesId(), $id, $product, Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        }

        expect(array_map(static fn ($email): string => $email->subject, CapturedEmails::installed()->sent))->toBe([
            'Your question about Oak chair is answered',
            'Your question about Oak chair is not published',
            'Your review of Oak chair is not published',
        ])
            ->and(CapturedEmails::installed()->sent[1]->text)->toContain('Reason: Off topic')
            ->and(CapturedEmails::installed()->sent[2]->text)->toContain('Reason: Rude words')
            ->and(array_map(static fn (object $entry): string => $entry->kind, eventMessagesBell($sara)))->toEqualCanonicalizing(['question_answered', 'question_not_published', 'review_not_published']);
    });

    it('tells an order that came back, a return accepted and a return received', function () {
        $sara = Fx::customer('sara@example.test');
        $returnId = strtolower((string) Str::ulid());
        eventMessagesSales($order = eventMessagesOrder($sara), new ReturnMessageDto($returnId, strtolower((string) Str::ulid()), 'SA-0007', Fx::storeId('sa'), $sara, 'ACCEPTED', null, null));

        event(new OrderCameBack(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        event(new ReturnAccepted(eventMessagesId(), $returnId, $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        event(new ReturnReceived(eventMessagesId(), $returnId, $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

        expect(array_map(static fn ($email): string => $email->subject, CapturedEmails::installed()->sent))->toBe([
            'Your order SA-0007 came back to us',
            'Your return for order SA-0007 is accepted',
            'We received your return for order SA-0007',
        ])
            ->and(array_map(static fn (object $entry): string => $entry->kind, eventMessagesBell($sara)))->toEqualCanonicalizing(['order_came_back', 'return_accepted', 'return_received'])
            ->and(eventMessagesSent($sara))->toEqualCanonicalizing(['order_came_back EMAIL SENT', 'return_accepted EMAIL SENT', 'return_received EMAIL SENT']);
    });

    it('skips what no longer stands: an approval of an order no longer approved, an acceptance of a return withdrawn since', function () {
        $sara = Fx::customer('sara@example.test');
        $returnId = strtolower((string) Str::ulid());
        eventMessagesSales($order = eventMessagesOrder($sara, status: 'CANCELLED'), new ReturnMessageDto($returnId, strtolower((string) Str::ulid()), 'SA-0007', Fx::storeId('sa'), $sara, 'WITHDRAWN', null, null));

        event(new OrderApproved(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        event(new ReturnAccepted(eventMessagesId(), $returnId, $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

        expect(eventMessagesBell($sara))->toBe([])
            ->and(CapturedEmails::installed()->sent)->toBe([])
            ->and(eventMessagesSent($sara))->toBe([]);
    });

    it('names no carrier on a shipment that has none', function () {
        $sara = Fx::customer('sara@example.test');
        eventMessagesSales($order = eventMessagesOrder($sara));

        event(new OrderShipped(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

        $text = CapturedEmails::installed()->last()->text;
        expect($text)->toContain('Your order SA-0007 has been shipped.')
            ->and(str_contains($text, 'shipped with'))->toBeFalse()
            ->and(eventMessagesBell($sara)[0]->line_en)->toBe('Your order is on its way.');
    });

    it('sends nothing when the read names another customer or store than the event, and logs only ids', function () {
        $log = Log::spy();
        $sara = Fx::customer('sara@example.test');
        $omar = Fx::customer('omar@example.test');
        eventMessagesSales($order = eventMessagesOrder($omar));
        event(new OrderApproved(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

        eventMessagesSales($elsewhere = eventMessagesOrder($sara, 'ae'));
        event(new OrderApproved(eventMessagesId(), $elsewhere->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

        expect(eventMessagesBell($sara))->toBe([])->and(eventMessagesBell($omar))->toBe([])
            ->and(CapturedEmails::installed()->sent)->toBe([]);
        $log->shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => ($context['read_customer_id'] ?? null) === strtolower($omar)
            && array_keys($context) === ['kind', 'event_store_id', 'read_store_id', 'event_customer_id', 'read_customer_id'])->atLeast()->once();
        $log->shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => ($context['read_store_id'] ?? null) === Fx::storeId('ae'))->atLeast()->once();
    });

    it('rolls a listener back whole: a failure halfway leaves no bell entry, no recorded message and no queued job', function () {
        // The database queue, as the live system runs: a job is a row written in the listener's transaction.
        config(['queue.default' => 'database']);
        $first = Fx::staffWith(['b2b.company.review'], ['sa']);
        $second = Fx::staffWith(['b2b.company.review'], ['sa']);
        eventMessagesSwitch($first, StaffNotificationTopic::CompanyApplications, panel: true, email: true);
        eventMessagesSwitch($second, StaffNotificationTopic::CompanyApplications, panel: true, email: true);
        $real = app(Inbox::class);
        app()->instance(Inbox::class, new class($real) implements Inbox
        {
            private int $adds = 0;

            public function __construct(private readonly Inbox $real) {}

            public function add(InboxEntry $entry): void
            {
                if (++$this->adds === 2) {
                    throw new RuntimeException('The database went away.');
                }

                $this->real->add($entry);
            }

            public function markRead(InboxOwner $owner, string $entryId, DateTimeImmutable $at): bool
            {
                return $this->real->markRead($owner, $entryId, $at);
            }

            public function markAllRead(InboxOwner $owner, DateTimeImmutable $at): int
            {
                return $this->real->markAllRead($owner, $at);
            }
        });
        $event = new CompanyApplicationSubmitted(eventMessagesId(), strtolower((string) Str::ulid()), strtolower((string) Str::ulid()), Fx::storeId('sa'), CarbonImmutable::now());

        $jobs = DB::table('jobs')->count();

        expect(fn () => app(AlertStaff::class)->handle($event))->toThrow(RuntimeException::class, 'The database went away.');

        expect(DB::table('ops.inbox_entries')->count())->toBe(0)
            ->and(DB::table('ops.sent_messages')->count())->toBe(0)
            ->and(DB::table('ops.handled_events')->where('event_id', $event->eventId)->count())->toBe(0)
            ->and(DB::table('jobs')->count())->toBe($jobs)
            ->and(CapturedEmails::installed()->sent)->toBe([]);

        // The same work, once the failure is gone, leaves both bells, both rows and both jobs.
        app()->forgetInstance(Inbox::class);
        app(AlertStaff::class)->handle($event);

        expect(DB::table('ops.inbox_entries')->count())->toBe(2)
            ->and(DB::table('ops.sent_messages')->where('status', 'QUEUED')->count())->toBe(2)
            ->and(DB::table('jobs')->count())->toBe($jobs + 2);
    });
});

describe('each email and SMS, sent then marked (§5, §7)', function () {
    it('writes the row QUEUED, sends, marks it SENT; a retry of a SENT row sends nothing', function () {
        $sara = Fx::customer('sara@example.test');
        eventMessagesSales($order = eventMessagesOrder($sara));
        $message = new EventMessage(MessageKind::OrderApproved, Fx::storeId('sa'), ['orderId' => $order->orderId]);
        $id = eventMessagesQueued($message, MessageChannel::Email, $sara, null);
        expect(eventMessagesSent($sara))->toBe(['order_approved EMAIL QUEUED']);

        $job = new SendEventEmailJob($message, $sara, null, $id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        expect(CapturedEmails::installed()->sent)->toHaveCount(1)
            ->and(eventMessagesSent($sara))->toBe(['order_approved EMAIL SENT'])
            ->and(DB::table('ops.sent_messages')->where('id', $id)->value('provider_id'))->toBe('message-1');
    });

    it('keeps a row QUEUED through a provider\'s failure, sends it on the retry, and marks FAILED only when the queue gives up', function () {
        $sara = Fx::customer('sara@example.test');
        eventMessagesSales($order = eventMessagesOrder($sara));
        $message = new EventMessage(MessageKind::OrderApproved, Fx::storeId('sa'), ['orderId' => $order->orderId]);
        $retried = eventMessagesQueued($message, MessageChannel::Email, $sara, null);
        $givenUp = eventMessagesQueued($message, MessageChannel::Email, $sara, null);

        CapturedEmails::installed()->fails = true;
        expect(fn () => app()->call([new SendEventEmailJob($message, $sara, null, $retried), 'handle']))->toThrow(ProviderFailed::class);
        expect(DB::table('ops.sent_messages')->where('id', $retried)->value('status'))->toBe('QUEUED');

        $job = new SendEventEmailJob($message, $sara, null, $givenUp);
        expect(fn () => app()->call([$job, 'handle']))->toThrow(ProviderFailed::class);
        $job->failed();

        CapturedEmails::installed()->fails = false;
        app()->call([new SendEventEmailJob($message, $sara, null, $retried), 'handle']);

        expect(DB::table('ops.sent_messages')->where('id', $retried)->value('status'))->toBe('SENT')
            ->and(DB::table('ops.sent_messages')->where('id', $givenUp)->value('status'))->toBe('FAILED')
            ->and(CapturedEmails::installed()->sent)->toHaveCount(1);
    });

    it('removes the row when there is nothing left to send, and sends nothing for a row already gone', function () {
        $sara = Fx::customer('sara@example.test');
        eventMessagesSales(null);
        $message = new EventMessage(MessageKind::OrderApproved, Fx::storeId('sa'), ['orderId' => strtolower((string) Str::ulid())]);
        $id = eventMessagesQueued($message, MessageChannel::Email, $sara, null);

        app()->call([new SendEventEmailJob($message, $sara, null, $id), 'handle']);
        app()->call([new SendEventEmailJob($message, $sara, null, strtolower((string) Str::ulid())), 'handle']);

        expect(eventMessagesSent($sara))->toBe([])->and(CapturedEmails::installed()->sent)->toBe([]);
    });
});

describe('the reminder before points expire (§1.5 C14)', function () {
    it('tells each customer once a day what expires a week from the day the job was queued, in every store, whatever runs it again', function () {
        $sara = Fx::customer('sara@example.test');
        $asked = [];
        $loyalty = Mockery::mock(LoyaltyApi::class);
        $loyalty->allows('expiring')->andReturnUsing(function ($store, DateTimeImmutable $from, DateTimeImmutable $to, ?string $after, int $limit) use (&$asked, $sara): ExpiringPointsPageDto {
            $asked[] = $store->value.' '.$from->format(DATE_ATOM).' '.$to->format(DATE_ATOM);

            return new ExpiringPointsPageDto($store->value === Fx::storeId('sa') ? [new ExpiringPointsDto($sara, 75)] : [], null);
        });
        app()->instance(LoyaltyApi::class, $loyalty);
        CarbonImmutable::setTestNow('2026-11-01 07:00:00');
        $job = new RemindExpiringPointsJob;
        // A worker that runs it two days late still reminds of the day it was queued for.
        CarbonImmutable::setTestNow('2026-11-03 07:00:00');

        app()->call([$job, 'handle']);
        app()->call([new RemindExpiringPointsJob('2026-11-01T07:00:00+00:00'), 'handle']);

        expect($asked)->toContain(Fx::storeId('sa').' 2026-11-08T00:00:00+03:00 2026-11-09T00:00:00+03:00')
            ->and($asked)->toContain(Fx::storeId('ae').' 2026-11-08T00:00:00+04:00 2026-11-09T00:00:00+04:00')
            ->and(CapturedEmails::installed()->sent)->toHaveCount(1)
            ->and(CapturedEmails::installed()->last()->subject)->toBe('Your points expire on 2026-11-08')
            ->and(CapturedEmails::installed()->last()->text)->toContain('75 of your points expire on 2026-11-08.')
            ->and(eventMessagesBell($sara))->toHaveCount(1);
    });
});

describe('alerts to staff (§1.4, §1.6)', function () {
    it('alerts the holders of the permission in the event\'s store by their switches: the bell on by default, email only when switched on', function () {
        $sara = Fx::customer('sara@example.test');
        eventMessagesSales($order = eventMessagesOrder($sara));
        $byDefault = Fx::staffWith(['sales.order.view'], ['sa']);
        $byEmail = Fx::staffWith(['sales.order.view'], ['sa']);
        eventMessagesSwitch($byEmail, StaffNotificationTopic::NewOrders, panel: false, email: true);
        $otherStore = Fx::staffWith(['sales.order.view'], ['ae']);
        $noPermission = Fx::staffWith(['sales.return.manage'], ['sa']);
        $disabled = Fx::staffWith(['sales.order.view'], ['sa']);
        DB::table('access.staff_users')->where('id', $disabled)->update(['status' => StaffStatus::Disabled->value]);
        cache()->flush();
        $event = new OrderPlaced(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now());

        event($event);
        event($event);

        $bell = eventMessagesBell(staffId: $byDefault);
        expect($bell)->toHaveCount(1)
            ->and([$bell[0]->kind, $bell[0]->title_en, $bell[0]->line_en, $bell[0]->link])->toBe(['new_order', 'New order SA-0007', 'A new order was placed in Saudi Arabia.', "/admin/orders/{$order->orderId}"])
            ->and(eventMessagesBell(staffId: $byEmail))->toBe([])
            ->and(eventMessagesBell(staffId: $otherStore))->toBe([])
            ->and(eventMessagesBell(staffId: $noPermission))->toBe([])
            ->and(eventMessagesBell(staffId: $disabled))->toBe([])
            ->and(CapturedEmails::installed()->to())->toEqualCanonicalizing(['sara@example.test', eventMessagesEmailOf($byEmail)])
            ->and(CapturedEmails::installed()->for(eventMessagesEmailOf($byEmail))->subject)->toBe('New order SA-0007 in Saudi Arabia')
            ->and(CapturedEmails::installed()->for(eventMessagesEmailOf($byEmail))->text)->toContain('Hello Staff,')
            ->and(eventMessagesSent(staffId: $byEmail))->toBe(['new_order EMAIL SENT'])
            ->and(eventMessagesSent(staffId: $byDefault))->toBe([]);
    });

    it('emails a staff member who reads Arabic in Arabic', function () {
        $reviewer = Fx::staffWith(['b2b.company.review'], ['sa']);
        DB::table('access.staff_users')->where('id', $reviewer)->update(['locale' => 'ar']);
        eventMessagesSwitch($reviewer, StaffNotificationTopic::CompanyApplications, panel: true, email: true);

        event(new CompanyApplicationSubmitted(eventMessagesId(), strtolower((string) Str::ulid()), strtolower((string) Str::ulid()), Fx::storeId('sa'), CarbonImmutable::now()));

        $email = CapturedEmails::installed()->for(eventMessagesEmailOf($reviewer));
        expect($email->subject)->toBe('طلب شركة جديد في السعودية')
            ->and($email->fromName)->toBe('السعودية')
            ->and($email->text)->toContain('مرحبًا Staff،');
    });

    it('alerts a company application, a return asked, and sync problems to their own permissions, linking to their pages; a return withdrawn since alerts no one', function () {
        $reviewer = Fx::staffWith(['b2b.company.review'], ['sa']);
        $returns = Fx::staffWith(['sales.return.manage'], ['sa']);
        $sync = Fx::staffWith(['sync.report.view'], ['sa']);
        $company = strtolower((string) Str::ulid());
        $returnId = strtolower((string) Str::ulid());
        eventMessagesSales(null, new ReturnMessageDto($returnId, strtolower((string) Str::ulid()), 'SA-0007', Fx::storeId('sa'), strtolower((string) Str::ulid()), 'REQUESTED', null, null));

        event(new CompanyApplicationSubmitted(eventMessagesId(), $company, strtolower((string) Str::ulid()), Fx::storeId('sa'), CarbonImmutable::now()));
        event(new ReturnRequested(eventMessagesId(), $returnId, strtolower((string) Str::ulid()), Fx::storeId('sa'), strtolower((string) Str::ulid()), CarbonImmutable::now()));
        event(new SyncNeedsAttention(eventMessagesId(), Fx::storeId('sa'), 'read-1', ['SALE_STOPPED' => 2], CarbonImmutable::now()));

        $withdrawn = strtolower((string) Str::ulid());
        eventMessagesSales(null, new ReturnMessageDto($withdrawn, strtolower((string) Str::ulid()), 'SA-0008', Fx::storeId('sa'), strtolower((string) Str::ulid()), 'WITHDRAWN', null, null));
        event(new ReturnRequested(eventMessagesId(), $withdrawn, strtolower((string) Str::ulid()), Fx::storeId('sa'), strtolower((string) Str::ulid()), CarbonImmutable::now()));

        expect(array_map(static fn (object $entry): string => "{$entry->kind} {$entry->link}", eventMessagesBell(staffId: $reviewer)))->toBe(["company_application /admin/companies/{$company}"])
            ->and(array_map(static fn (object $entry): string => "{$entry->kind} {$entry->link}", eventMessagesBell(staffId: $returns)))->toBe(["return_requested /admin/returns/{$returnId}"])
            ->and(array_map(static fn (object $entry): string => "{$entry->kind} {$entry->link}", eventMessagesBell(staffId: $sync)))->toBe(['sync_problems /admin/sync']);
    });

    it('alerts sizes that became low or ran out, with their counts; a recount where sizes only came back alerts no one', function () {
        $stock = Fx::staffWith(['inventory.stock.manage'], ['sa']);

        event(new LowStockChanged(eventMessagesId(), Fx::storeId('sa'), ['a', 'b', 'c'], ['d'], [], CarbonImmutable::now()));
        event(new LowStockChanged(eventMessagesId(), Fx::storeId('sa'), [], [], ['e'], CarbonImmutable::now()));

        $bell = eventMessagesBell(staffId: $stock);
        expect($bell)->toHaveCount(1)
            ->and($bell[0]->line_en)->toBe('3 sizes are low and 1 ran out.');
    });

    it('never sends an SMS for sync problems, even to a super admin with the critical-SMS switch on', function () {
        $super = Fx::staff(superAdmin: true);
        $sms = RecordingSmsSenders::install();

        event(new SyncNeedsAttention(eventMessagesId(), Fx::storeId('sa'), 'read-1', ['SALE_STOPPED' => 1], CarbonImmutable::now()));

        expect(eventMessagesBell(staffId: $super))->toHaveCount(1)
            ->and($sms->sent)->toBe([]);
    });
});

describe('the critical SMS (§1.6: the gateway key at 1 day, or refused)', function () {
    /**
     * The Stage 7 events are not in main yet (GatewayKeyExpiring, GatewayKeyRejected) and Payments
     * declares no `payments.gateway.manage` yet: the alert is driven here as their listener will drive
     * it, with Access's read answering for the people given.
     */
    it('queues the SMS only at 1 day or for a refused key, only to super admins whose switch is on and who have a phone', function () {
        $deliveries = new class implements EventDeliveries
        {
            /** @var list<string> */
            public array $sms = [];

            public function emailCustomer(EventMessage $message, string $customerId): void {}

            public function emailStaff(EventMessage $message, string $staffId): void {}

            public function smsStaff(EventMessage $message, string $staffId, StaffNotificationTopic $topic, string $permission): void
            {
                $this->sms[] = $message->kind->value.' '.$message->int('days').' '.$staffId;
            }
        };
        app()->instance(EventDeliveries::class, $deliveries);
        $on = Fx::staff(superAdmin: true);
        $off = Fx::staff(superAdmin: true);
        $reset = Fx::staff(superAdmin: true);
        $admin = Fx::staff();
        $real = app(AccessApi::class);
        $access = Mockery::mock(AccessApi::class);
        $access->allows('staffToAlert')->andReturn([
            new StaffAlertRecipientDto($on, 'en', 'on@example.test', '+966500000001', true, false, criticalSms: true),
            new StaffAlertRecipientDto($off, 'en', 'off@example.test', '+966500000002', true, false, criticalSms: false),
            new StaffAlertRecipientDto($reset, 'en', 'reset@example.test', null, true, false, criticalSms: true),
            // An admin the permission reaches, switch on: never the SMS (super admins only).
            new StaffAlertRecipientDto($admin, 'en', 'admin@example.test', '+966500000003', true, false, criticalSms: true),
        ]);
        $access->allows('staff')->andReturnUsing(static fn (string $id) => $real->staff($id));
        app()->instance(AccessApi::class, $access);
        $alerts = app(StaffAlerts::class);

        foreach ([30, 7, 1] as $days) {
            $alerts->alert(new EventMessage(MessageKind::GatewayKeyExpiring, Fx::storeId('sa'), ['days' => $days, 'date' => '2026-11-30']), StaffNotificationTopic::PaymentProblems, 'payments.gateway.manage');
        }

        $alerts->alert(new EventMessage(MessageKind::GatewayKeyRejected, Fx::storeId('sa'), []), StaffNotificationTopic::PaymentProblems, 'payments.gateway.manage');

        expect($deliveries->sms)->toBe(["gateway_key_expiring 1 {$on}", "gateway_key_rejected 0 {$on}"])
            ->and(eventMessagesBell(staffId: $on))->toHaveCount(4)
            ->and(eventMessagesBell(staffId: $admin))->toHaveCount(4);
    });

    it('sends it through the provider of the phone\'s country, in the person\'s language, and records it', function () {
        $super = Fx::staff(superAdmin: true);
        $sms = RecordingSmsSenders::install();
        DB::table('ops.sms_countries')->where('country_code', 'SA')->update(['provider' => 'second']);
        $phone = (string) DB::table('access.staff_users')->where('id', $super)->value('phone');
        $refused = new EventMessage(MessageKind::GatewayKeyRejected, Fx::storeId('sa'), []);
        $sevenDays = new EventMessage(MessageKind::GatewayKeyExpiring, Fx::storeId('sa'), ['days' => 7, 'date' => '2026-11-30']);

        eventMessagesSms($refused, $super);
        eventMessagesSms($sevenDays, $super);

        expect($sms->sent)->toBe([['provider' => 'second', 'phone' => $phone, 'message' => 'TouchWood: the payment gateway refused the key of Saudi Arabia. Replace it now.']])
            ->and(eventMessagesSent(staffId: $super))->toBe(['gateway_key_rejected SMS SENT']);
    });

    it('checks again when it leaves: an admin, or a super admin who switched it off meanwhile, gets nothing', function () {
        $admin = Fx::staffWith(['sales.order.view'], ['sa'], RoleLevel::Admin);
        $switchedOff = Fx::staff(superAdmin: true);
        DB::table('access.staff_users')->where('id', $switchedOff)->update(['critical_sms' => false]);
        cache()->flush();
        $sms = RecordingSmsSenders::install();
        $refused = new EventMessage(MessageKind::GatewayKeyRejected, Fx::storeId('sa'), []);

        expect((bool) DB::table('access.staff_users')->where('id', $admin)->value('critical_sms'))->toBeTrue();

        eventMessagesSms($refused, $admin);
        eventMessagesSms($refused, $switchedOff);

        expect($sms->sent)->toBe([])
            ->and(eventMessagesSent(staffId: $admin))->toBe([])
            ->and(eventMessagesSent(staffId: $switchedOff))->toBe([]);
    });

    it('fails a phone whose country is not on the SMS countries list, marked FAILED once the queue gives up', function () {
        $super = Fx::staff(superAdmin: true);
        RecordingSmsSenders::install();
        DB::table('access.staff_users')->where('id', $super)->update(['phone' => '+201000000001']);
        DB::table('ops.sms_countries')->where('country_code', 'EG')->delete();
        cache()->flush();
        $message = new EventMessage(MessageKind::GatewayKeyRejected, Fx::storeId('sa'), []);
        $id = eventMessagesQueued($message, MessageChannel::Sms, null, $super);
        $job = new SendEventSmsJob($message, $super, StaffNotificationTopic::PaymentProblems, 'sales.order.view', $id);

        expect(fn () => app()->call([$job, 'handle']))->toThrow(ProviderFailed::class);
        $job->failed();

        expect(eventMessagesSent(staffId: $super))->toBe(['gateway_key_rejected SMS FAILED']);
    });
});
