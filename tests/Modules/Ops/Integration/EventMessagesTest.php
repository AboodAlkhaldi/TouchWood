<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
use Modules\Ops\Infrastructure\Queue\RemindExpiringPointsJob;
use Modules\Ops\Infrastructure\Queue\SendEventEmailJob;
use Modules\Ops\Public\Enums\MessageChannel;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Dto\OrderMessageDto;
use Modules\Sales\Public\Dto\OrderMessageLineDto;
use Modules\Sales\Public\Dto\ReturnMessageDto;
use Modules\Sales\Public\Events\OrderApproved;
use Modules\Sales\Public\Events\OrderCancelled;
use Modules\Sales\Public\Events\OrderDelivered;
use Modules\Sales\Public\Events\OrderEdited;
use Modules\Sales\Public\Events\OrderPlaced;
use Modules\Sales\Public\Events\OrderRejected;
use Modules\Sales\Public\Events\OrderShipped;
use Modules\Sales\Public\Events\ReturnCompleted;
use Modules\Sales\Public\Events\ReturnRefused;
use Modules\Sales\Public\Events\ReturnRequested;
use Modules\Sync\Public\Events\SyncNeedsAttention;
use Shared\Domain\ValueObject\Money;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\RecordingSmsSenders;
use Tests\Modules\Ops\Support\WrittenFixtures as Wx;

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

/** Sales' read for messages, answering this order for any id, and this return. */
function eventMessagesSales(?OrderMessageDto $order, ?ReturnMessageDto $return = null): void
{
    $sales = Mockery::mock(SalesReports::class);
    $sales->allows('orderForMessage')->andReturnUsing(static fn (string $id, string $locale): ?OrderMessageDto => $order === null ? null : new OrderMessageDto(
        $id, $order->number, $order->storeId, $order->customerId, $order->locale, $order->status, $order->shipmentStatus, $order->wayToPay,
        $locale === 'ar' ? [new OrderMessageLineDto('كرسي', [['attribute' => 'اللون', 'value' => 'بني']], 2, Money::of(20000, 'SAR'), false)] : $order->lines,
        $order->subtotal, $order->couponDiscount, $order->pointsDiscount, $order->shipping, $order->vat, $order->total, $order->carrier, $order->trackingNumber, $order->rejectionReason, $order->cancelReason,
    ));
    $sales->allows('returnForMessage')->andReturn($return);
    app()->instance(SalesReports::class, $sales);
}

function eventMessagesOrder(string $customerId, string $storeCode = 'sa', ?string $rejection = null, ?string $carrier = null, ?string $tracking = null, int $coupon = 0): OrderMessageDto
{
    return new OrderMessageDto(
        strtolower((string) Str::ulid()), 'SA-0007', Fx::storeId($storeCode), $customerId, 'en', 'APPROVED', 'NOT_SHIPPED', 'BANK_TRANSFER',
        [new OrderMessageLineDto('Chair', [['attribute' => 'Colour', 'value' => 'Brown']], 2, Money::of(20000, 'SAR'), false)],
        Money::of(20000, 'SAR'), Money::of($coupon, 'SAR'), Money::of(0, 'SAR'), Money::of(2500, 'SAR'), Money::of(3375, 'SAR'), Money::of(25875 - $coupon, 'SAR'),
        $carrier, $tracking, $rejection, null,
    );
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
    return DB::table('ops.sent_messages')
        ->when($customerId !== null, fn ($query) => $query->where('customer_id', strtolower((string) $customerId)))
        ->when($staffId !== null, fn ($query) => $query->where('staff_id', strtolower((string) $staffId)))
        ->orderBy('created_at')->get()
        ->map(static fn (object $row): string => "{$row->kind} {$row->channel} {$row->status}")->values()->all();
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
            ->and($shipped)->toContain('has been shipped with SMSA')->toContain('Tracking number: TRK123')->not->toContain('Track your order');
    });

    it('tells the store\'s return window on delivery, and a cancellation, consent or not: service messages ignore it', function () {
        $sara = Fx::customer('sara@example.test');
        Wx::set($sara, ['marketing_consent' => false]);
        eventMessagesSales($order = eventMessagesOrder($sara));

        event(new OrderDelivered(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        event(new OrderCancelled(eventMessagesId(), $order->orderId, Fx::storeId('sa'), $sara, CarbonImmutable::now()));

        expect(CapturedEmails::installed()->sent[0]->text)->toContain('within 14 days of delivery')
            ->and(CapturedEmails::installed()->sent[1]->subject)->toBe('Your order SA-0007 is cancelled')
            ->and(eventMessagesSent($sara))->toBe(['order_delivered EMAIL SENT', 'order_cancelled EMAIL SENT']);
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

    it('records a provider\'s failure the queue gave up on, and sends each email once by its delivery id', function () {
        $sara = Fx::customer('sara@example.test');
        eventMessagesSales($order = eventMessagesOrder($sara));
        $message = new EventMessage(MessageKind::OrderApproved, Fx::storeId('sa'), ['orderId' => $order->orderId]);
        $job = new SendEventEmailJob($message, $sara, null, eventMessagesId());

        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        expect(CapturedEmails::installed()->sent)->toHaveCount(1);

        CapturedEmails::installed()->fails = true;
        $again = new SendEventEmailJob($message, $sara, null, eventMessagesId());
        expect(fn () => app()->call([$again, 'handle']))->toThrow(ProviderFailed::class);
        $again->failed();

        expect(eventMessagesSent($sara))->toBe(['order_approved EMAIL SENT', 'order_approved EMAIL FAILED']);
    });
});

describe('the reminder before points expire (§1.5 C14)', function () {
    it('tells each customer once a day what expires a week ahead, in every store, whatever runs it again', function () {
        CarbonImmutable::setTestNow('2026-11-01 07:00:00');
        $sara = Fx::customer('sara@example.test');
        $asked = [];
        $loyalty = Mockery::mock(LoyaltyApi::class);
        $loyalty->allows('expiring')->andReturnUsing(function ($store, DateTimeImmutable $from, DateTimeImmutable $to, ?string $after, int $limit) use (&$asked, $sara): ExpiringPointsPageDto {
            $asked[] = $store->value.' '.$from->format(DATE_ATOM).' '.$to->format(DATE_ATOM);

            return new ExpiringPointsPageDto($store->value === Fx::storeId('sa') ? [new ExpiringPointsDto($sara, 75)] : [], null);
        });
        app()->instance(LoyaltyApi::class, $loyalty);

        app()->call([new RemindExpiringPointsJob, 'handle']);
        app()->call([new RemindExpiringPointsJob, 'handle']);

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

    it('alerts a company application, a return asked, and sync problems to their own permissions, linking to their pages', function () {
        $reviewer = Fx::staffWith(['b2b.company.review'], ['sa']);
        $returns = Fx::staffWith(['sales.return.manage'], ['sa']);
        $sync = Fx::staffWith(['sync.report.view'], ['sa']);
        $company = strtolower((string) Str::ulid());
        $returnId = strtolower((string) Str::ulid());
        eventMessagesSales(null, new ReturnMessageDto($returnId, strtolower((string) Str::ulid()), 'SA-0007', Fx::storeId('sa'), strtolower((string) Str::ulid()), 'REQUESTED', null, null));

        event(new CompanyApplicationSubmitted(eventMessagesId(), $company, strtolower((string) Str::ulid()), Fx::storeId('sa'), CarbonImmutable::now()));
        event(new ReturnRequested(eventMessagesId(), $returnId, strtolower((string) Str::ulid()), Fx::storeId('sa'), strtolower((string) Str::ulid()), CarbonImmutable::now()));
        event(new SyncNeedsAttention(eventMessagesId(), Fx::storeId('sa'), 'read-1', ['SALE_STOPPED' => 2], CarbonImmutable::now()));

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
     * it, with Access's read answering two super admins.
     */
    it('queues the SMS only at 1 day or for a refused key, only to those whose switch is on and who have a phone', function () {
        $deliveries = new class implements EventDeliveries
        {
            /** @var list<string> */
            public array $sms = [];

            public function emailCustomer(EventMessage $message, string $customerId): void {}

            public function emailStaff(EventMessage $message, string $staffId): void {}

            public function smsStaff(EventMessage $message, string $staffId): void
            {
                $this->sms[] = $message->kind->value.' '.$message->int('days').' '.$staffId;
            }
        };
        app()->instance(EventDeliveries::class, $deliveries);
        $on = Fx::staff(superAdmin: true);
        $off = Fx::staff(superAdmin: true);
        $access = Mockery::mock(AccessApi::class);
        $access->allows('staffToAlert')->andReturn([
            new StaffAlertRecipientDto($on, 'en', 'on@example.test', '+966500000001', true, false, criticalSms: true),
            new StaffAlertRecipientDto($off, 'en', 'off@example.test', '+966500000002', true, false, criticalSms: false),
            new StaffAlertRecipientDto(Fx::staff(superAdmin: true), 'en', 'reset@example.test', null, true, false, criticalSms: true),
        ]);
        app()->instance(AccessApi::class, $access);
        $alerts = app(StaffAlerts::class);

        foreach ([30, 7, 1] as $days) {
            $alerts->alert(new EventMessage(MessageKind::GatewayKeyExpiring, Fx::storeId('sa'), ['days' => $days, 'date' => '2026-11-30']), StaffNotificationTopic::PaymentProblems, 'payments.gateway.manage');
        }

        $alerts->alert(new EventMessage(MessageKind::GatewayKeyRejected, Fx::storeId('sa'), []), StaffNotificationTopic::PaymentProblems, 'payments.gateway.manage');

        expect($deliveries->sms)->toBe(["gateway_key_expiring 1 {$on}", "gateway_key_rejected 0 {$on}"])
            ->and(eventMessagesBell(staffId: $on))->toHaveCount(4);
    });

    it('sends it through the provider of the phone\'s country, in the person\'s language, and records it', function () {
        $super = Fx::staff(superAdmin: true);
        $sms = RecordingSmsSenders::install();
        DB::table('ops.sms_countries')->where('country_code', 'SA')->update(['provider' => 'second']);
        $phone = (string) DB::table('access.staff_users')->where('id', $super)->value('phone');

        app(EventSender::class)->smsStaff(new EventMessage(MessageKind::GatewayKeyRejected, Fx::storeId('sa'), []), $super);
        app(EventSender::class)->smsStaff(new EventMessage(MessageKind::GatewayKeyExpiring, Fx::storeId('sa'), ['days' => 7, 'date' => '2026-11-30']), $super);

        expect($sms->sent)->toBe([['provider' => 'second', 'phone' => $phone, 'message' => 'TouchWood: the payment gateway refused the key of Saudi Arabia. Replace it now.']])
            ->and(eventMessagesSent(staffId: $super))->toBe(['gateway_key_rejected SMS SENT']);
    });

    it('fails a phone whose country is not on the SMS countries list, recorded once the queue gives up', function () {
        $super = Fx::staff(superAdmin: true);
        RecordingSmsSenders::install();
        DB::table('access.staff_users')->where('id', $super)->update(['phone' => '+201000000001']);
        DB::table('ops.sms_countries')->where('country_code', 'EG')->delete();
        cache()->flush();
        $message = new EventMessage(MessageKind::GatewayKeyRejected, Fx::storeId('sa'), []);

        expect(fn () => app(EventSender::class)->smsStaff($message, $super))->toThrow(ProviderFailed::class);
        app(EventSender::class)->failed($message, null, $super, MessageChannel::Sms);

        expect(eventMessagesSent(staffId: $super))->toBe(['gateway_key_rejected SMS FAILED']);
    });
});
