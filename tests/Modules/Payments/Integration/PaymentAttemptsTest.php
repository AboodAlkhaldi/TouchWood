<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Payments\Application\Attempt\AttemptChecks;
use Modules\Payments\Application\Attempt\AttemptSweep;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Command\SwitchMode\SwitchMode;
use Modules\Payments\Application\Command\SwitchMode\SwitchModeHandler;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Application\Notice\NoticeHandling;
use Modules\Payments\Domain\Exception\AttemptNotFound;
use Modules\Payments\Domain\Exception\InvalidPaymentsInput;
use Modules\Payments\Domain\Exception\NoOnlinePayment;
use Modules\Payments\Domain\Exception\PaymentInProgress;
use Modules\Payments\Domain\Exception\SessionNotUsable;
use Modules\Payments\Infrastructure\Listener\ClearCustomerFromAttempts;
use Modules\Payments\Infrastructure\Queue\CheckAttemptJob;
use Modules\Payments\Infrastructure\Queue\HandleNoticeJob;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\PayRequestDto;
use Modules\Payments\Public\Dto\SessionRequestDto;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Payments\Public\Enums\PaymentOwnerKind;
use Modules\Payments\Public\Enums\PaymentState;
use Modules\Payments\Public\Events\PaymentFailed;
use Modules\Payments\Public\Events\PaymentNotAccepted;
use Modules\Payments\Public\Events\PaymentNotTaken;
use Modules\Payments\Public\Events\PaymentSucceeded;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Payments\Support\FakeMyFatoorah;
use Tests\Modules\Payments\Support\MyFatoorahAnswers as Answers;

use function Pest\Laravel\call;
use function Pest\Laravel\seed;

/*
| The attempt (payments.md §1.1, §1.4, §1.7, §4; its scenarios in §8), against a faked MyFatoorah: never twice, never
| on nothing, never lost.
*/

uses(RefreshDatabase::class);

const ATTEMPTS_QUOTE = 'quote-1';

const ATTEMPTS_TRY = 'try-1';

const ATTEMPTS_ORDER = 'order-1';

const ATTEMPTS_BUYER = 'buyer-1';

beforeEach(function () {
    seed(PlatformSeeder::class);
    app()->instance(KeyTester::class, new class implements KeyTester
    {
        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void {}
    });
    Sleep::fake();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    CarbonImmutable::setTestNow('2027-01-01T12:00:00Z');
    Carbon::setTestNow('2027-01-01T12:00:00Z');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

function attemptsAt(string $when): void
{
    $moment = CarbonImmutable::parse('2027-01-01T12:00:00Z')->modify($when);
    CarbonImmutable::setTestNow($moment);
    Carbon::setTestNow($moment);
}

function attemptsGateway(string $mode = 'TEST'): string
{
    $store = Fx::storeId('sa');
    app(ChangeKeysHandler::class)->handle(new ChangeKeys($store, $mode, 'sk_key_ABC123', 'whsec_secret_1', '2027-12-31'));
    app(SwitchGatewayHandler::class)->handle(new SwitchGateway($store, true));

    return $store;
}

function attemptsSession(string $store, string $owner = ATTEMPTS_QUOTE, int $amount = 118050): string
{
    return app(PaymentsApi::class)->openSession(new SessionRequestDto(StoreId::fromString($store), $owner, ATTEMPTS_BUYER, Money::of($amount, 'SAR'), 'en', CarbonImmutable::now()->addMinutes(30)))->sessionId;
}

/**
 * @param  array<string, mixed>  $changes
 */
function attemptsRequest(string $store, string $session, array $changes = []): PayRequestDto
{
    $values = [
        'ownerId' => ATTEMPTS_TRY, 'ownerKind' => PaymentOwnerKind::Try, 'orderId' => ATTEMPTS_ORDER, 'quoteId' => ATTEMPTS_QUOTE, 'storeId' => StoreId::fromString($store),
        'buyerId' => ATTEMPTS_BUYER, 'sessionId' => $session, 'amount' => Money::of(118050, 'SAR'), 'customerName' => 'A Buyer', 'customerEmail' => 'buyer@example.test',
        'customerPhone' => '+966500000000', 'locale' => 'en', 'returnUrl' => 'https://shop.example.test/return',
        ...$changes,
    ];

    return new PayRequestDto(...$values);
}

/**
 * A store with its gateway on, an attempt prepared, and the fake that will answer.
 *
 * @return array{fake: FakeMyFatoorah, store: string, id: string}
 */
function attemptsPrepared(string $mode = 'TEST'): array
{
    $fake = FakeMyFatoorah::install();
    $store = attemptsGateway($mode);
    $id = app(PaymentsApi::class)->prepare(attemptsRequest($store, attemptsSession($store)));

    return ['fake' => $fake, 'store' => $store, 'id' => $id];
}

/**
 * @return array{fake: FakeMyFatoorah, store: string, id: string}
 */
function attemptsCharged(string $mode = 'TEST'): array
{
    $ready = attemptsPrepared($mode);
    app(PaymentsApi::class)->charge($ready['id']);

    return $ready;
}

function attemptsRow(string $id): stdClass
{
    return DB::table('payments.attempts')->where('id', $id)->sole();
}

/** @param class-string $event */
function attemptsEvents(string $event): int
{
    return DB::table('public.outbox_messages')->where('type', $event)->count();
}

function attemptsLastRequest(): Request
{
    $last = collect(Http::recorded())->map(fn (array $pair): Request => $pair[0])->last();

    return $last ?? throw new LogicException('Nothing was sent');
}

function attemptsCheck(string $id): void
{
    app(AttemptChecks::class)->due($id);
}

/**
 * @return array<string, mixed>
 */
function attemptsNotice(string $paymentId = FakeMyFatoorah::PAYMENT_ID, ?string $attempt = null, ?string $invoice = FakeMyFatoorah::INVOICE_ID): array
{
    return ['Event' => ['Code' => 1, 'Reference' => 'evt-'.Str::uuid()], 'Data' => ['Invoice' => ['Id' => $invoice, 'Status' => 'PAID', 'ExternalIdentifier' => $attempt], 'Transaction' => ['Status' => 'SUCCESS', 'PaymentId' => $paymentId]]];
}

/**
 * @param  array<string, mixed>  $payload
 */
function attemptsHandleNotice(string $store, array $payload, string $mode = 'TEST'): string
{
    $id = (string) DB::table('payments.notices')->insertGetId([
        'gateway' => 'MYFATOORAH', 'store_id' => $store, 'mode' => $mode, 'event_reference' => 'evt-'.Str::uuid(), 'received_at' => CarbonImmutable::now(), 'payload' => json_encode($payload),
    ]);
    app(NoticeHandling::class)->handle($id);

    return $id;
}

describe('preparing (§1.4 step 3, rule 7)', function () {
    it('writes the attempt with no call to the gateway, its key made and its times set', function () {
        $fake = FakeMyFatoorah::install();
        $store = attemptsGateway();
        $session = attemptsSession($store);
        $before = count($fake->calls);

        $id = app(PaymentsApi::class)->prepare(attemptsRequest($store, $session));

        $row = attemptsRow($id);
        expect(count($fake->calls))->toBe($before)
            ->and([$row->state, $row->owner_kind, $row->owner_id, $row->order_id, $row->session_id, (int) $row->amount_minor, $row->currency_code, $row->mode, $row->customer_email])
            ->toBe(['CREATING', 'TRY', ATTEMPTS_TRY, ATTEMPTS_ORDER, $session, 118050, 'SAR', 'TEST', 'buyer@example.test'])
            ->and(Str::isUuid((string) $row->idempotency_key))->toBeTrue()
            ->and(CarbonImmutable::parse($row->window_ends_at)->toIso8601ZuluString())->toBe('2027-01-01T12:05:00Z')
            ->and(CarbonImmutable::parse($row->wait_ends_at)->toIso8601ZuluString())->toBe('2027-01-01T12:07:00Z')
            ->and(CarbonImmutable::parse($row->hold_ends_at)->toIso8601ZuluString())->toBe('2027-01-01T12:15:00Z')
            ->and(CarbonImmutable::parse($row->next_check_at)->toIso8601ZuluString())->toBe('2027-01-01T12:05:00Z')
            ->and(DB::table('payments.sessions')->where('id', $session)->value('used_by'))->toBe($id);
    });

    it('takes a request\'s session under the request\'s own id', function () {
        FakeMyFatoorah::install();
        $store = attemptsGateway();
        $session = attemptsSession($store, 'request-1');

        $id = app(PaymentsApi::class)->prepare(attemptsRequest($store, $session, ['ownerId' => 'request-1', 'ownerKind' => PaymentOwnerKind::Request, 'quoteId' => null]));

        expect(attemptsRow($id)->owner_kind)->toBe('REQUEST');
    });

    it('refuses a session that is not this page\'s', function (Closure $request) {
        FakeMyFatoorah::install();
        $store = attemptsGateway();
        $session = attemptsSession($store);

        expect(fn () => app(PaymentsApi::class)->prepare($request($store, $session)))->toThrow(SessionNotUsable::class)
            ->and(DB::table('payments.attempts')->count())->toBe(0);
    })->with([
        'one we never opened' => [fn (string $store, string $session) => attemptsRequest($store, 'KWT-someone-elses')],
        'another quote\'s' => [fn (string $store, string $session) => attemptsRequest($store, $session, ['quoteId' => 'quote-2'])],
        'another buyer\'s' => [fn (string $store, string $session) => attemptsRequest($store, $session, ['buyerId' => 'buyer-2'])],
        'for another amount' => [fn (string $store, string $session) => attemptsRequest($store, $session, ['amount' => Money::of(50000, 'SAR')])],
        'in another currency' => [fn (string $store, string $session) => attemptsRequest($store, $session, ['amount' => Money::of(118050, 'EGP')])],
    ]);

    it('refuses a session of another store, an expired one and a used one', function () {
        FakeMyFatoorah::install();
        $store = attemptsGateway();
        $session = attemptsSession($store);
        $other = Fx::storeId('eg');
        app(ChangeKeysHandler::class)->handle(new ChangeKeys($other, 'TEST', 'sk_key_EGP123', 'whsec_secret_2', '2027-12-31'));
        app(SwitchGatewayHandler::class)->handle(new SwitchGateway($other, true));

        expect(fn () => app(PaymentsApi::class)->prepare(attemptsRequest($other, $session, ['storeId' => StoreId::fromString($other)])))->toThrow(SessionNotUsable::class);

        attemptsAt('+31 minutes');

        expect(fn () => app(PaymentsApi::class)->prepare(attemptsRequest($store, $session)))->toThrow(SessionNotUsable::class);

        DB::table('payments.sessions')->update(['expires_at' => CarbonImmutable::now()->addHour(), 'used_by' => strtolower((string) Str::ulid())]);

        expect(fn () => app(PaymentsApi::class)->prepare(attemptsRequest($store, $session)))->toThrow(SessionNotUsable::class);
    });

    it('refuses while the gateway is off, or its key refused, and an address that cannot be a return', function () {
        FakeMyFatoorah::install();
        $store = attemptsGateway();
        $session = attemptsSession($store);

        expect(fn () => app(PaymentsApi::class)->prepare(attemptsRequest($store, $session, ['returnUrl' => 'javascript:alert(1)'])))->toThrow(InvalidPaymentsInput::class);

        app(SwitchGatewayHandler::class)->handle(new SwitchGateway($store, false));

        expect(fn () => app(PaymentsApi::class)->prepare(attemptsRequest($store, $session)))->toThrow(NoOnlinePayment::class);

        app(SwitchGatewayHandler::class)->handle(new SwitchGateway($store, true));
        DB::table('payments.gateway_keys')->update(['rejected_at' => now()]);

        expect(fn () => app(PaymentsApi::class)->prepare(attemptsRequest($store, $session)))->toThrow(NoOnlinePayment::class);
    });

    it('gives a double click the same attempt, and another session "already in progress" (rule 3)', function () {
        ['store' => $store, 'id' => $id] = attemptsPrepared();
        $session = (string) attemptsRow($id)->session_id;

        expect(app(PaymentsApi::class)->prepare(attemptsRequest($store, $session)))->toBe($id)
            ->and(fn () => app(PaymentsApi::class)->prepare(attemptsRequest($store, attemptsSession($store, 'quote-2'), ['quoteId' => 'quote-2'])))->toThrow(PaymentInProgress::class)
            ->and(DB::table('payments.attempts')->count())->toBe(1);
    });

    it('allows a database with one open attempt per owner only', function () {
        ['id' => $id] = attemptsPrepared();
        $row = (array) attemptsRow($id);

        expect(fn () => DB::table('payments.attempts')->insert([...$row, 'id' => strtolower((string) Str::ulid()), 'idempotency_key' => (string) Str::uuid()]))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('charging (rule 4, §1.4 step 4)', function () {
    it('claims first, then calls once with the session, the key, our id, and the answer kept', function () {
        ['fake' => $fake, 'store' => $store, 'id' => $id] = attemptsPrepared();

        $start = app(PaymentsApi::class)->charge($id);

        $row = attemptsRow($id);
        $charge = attemptsLastRequest();

        expect([$start->attemptId, $start->frameUrl, $start->state])->toBe([$id, FakeMyFatoorah::FRAME_URL, PaymentState::InProgress])
            ->and($start->windowEndsAt->format('H:i'))->toBe('12:05')
            ->and([$row->state, $row->invoice_id, $row->gateway_payment_id, $row->frame_url])->toBe(['OPEN', FakeMyFatoorah::INVOICE_ID, FakeMyFatoorah::PAYMENT_ID, FakeMyFatoorah::FRAME_URL])
            ->and($row->charge_claimed_at)->not->toBeNull()
            ->and($fake->count('POST', '/v3/payments'))->toBe(1)
            ->and($fake->idempotencyKeys)->toBe([(string) $row->idempotency_key])
            ->and($charge->data())->toMatchArray([
                'SourceOfFund' => ['SessionId' => $row->session_id],
                'Order' => ['Amount' => 1180.5, 'Currency' => 'SAR', 'ExternalIdentifier' => $id],
                'OperationType' => 'PAY',
                'PaymentExpiry' => '2027-01-01T12:05:00Z',
                'Customer' => ['Reference' => ATTEMPTS_TRY],
                'Language' => 'EN',
            ])
            ->and($charge->data()['IntegrationUrls']['Redirection'])->toBe('https://shop.example.test/return')
            ->and($charge->data()['IntegrationUrls']['Webhook'])->toEndWith("/payments/notice/{$store}/test")
            ->and((string) json_encode($charge->data()))->not->toContain('buyer@example.test');
    });

    it('makes one call for a double click: the second finds the claim, and gets the same answer (§8 #44)', function () {
        ['fake' => $fake, 'id' => $id] = attemptsPrepared();

        $first = app(PaymentsApi::class)->charge($id);
        $second = app(PaymentsApi::class)->charge($id);

        expect($fake->count('POST', '/v3/payments'))->toBe(1)
            ->and($second->frameUrl)->toBe($first->frameUrl)
            ->and($second->state)->toBe($first->state);
    });

    it('repeats a charge with no answer with the same key, and gets one invoice (§8 #12)', function () {
        ['fake' => $fake, 'id' => $id] = attemptsPrepared();
        $fake->charges = [fn () => Http::response([], 503), fn () => throw new ConnectionException('timed out')];

        $start = app(PaymentsApi::class)->charge($id);

        expect($fake->count('POST', '/v3/payments'))->toBe(3)
            ->and(array_unique($fake->idempotencyKeys))->toBe([(string) attemptsRow($id)->idempotency_key])
            ->and($start->state)->toBe(PaymentState::InProgress);
    });

    it('never calls it failed when nothing answers: "we do not know", and the session settles it (§8 #35)', function () {
        ['fake' => $fake, 'id' => $id] = attemptsPrepared();
        $fake->charges = [fn () => Http::response([], 503), fn () => Http::response([], 503), fn () => Http::response([], 503)];

        $start = app(PaymentsApi::class)->charge($id);

        expect($start->state)->toBe(PaymentState::Unknown)
            ->and($start->nothingCharged)->toBeFalse()
            ->and(attemptsEvents(PaymentNotTaken::class))->toBe(0)
            ->and(attemptsEvents(PaymentFailed::class))->toBe(0);
    });

    it('takes a 4xx on the first call as refused: released at once, nothing charged', function () {
        ['fake' => $fake, 'id' => $id] = attemptsPrepared();
        $fake->charges = [fn () => Http::response(Answers::refusal('SessionId is expired!'), 400)];

        $start = app(PaymentsApi::class)->charge($id);

        $row = attemptsRow($id);
        expect($start->state)->toBe(PaymentState::Failed)
            ->and($start->nothingCharged)->toBeTrue()
            ->and([$row->state, $row->nothing_charged, $row->next_check_at])->toBe(['REFUSED', true, null])
            ->and((string) $row->refused_reason)->toContain('SessionId is expired!')
            ->and(attemptsEvents(PaymentNotTaken::class))->toBe(1);
    });

    it('never takes a 4xx on a repeat as refused: the first call may have gone through (§8 #35)', function () {
        ['fake' => $fake, 'id' => $id] = attemptsPrepared();
        $fake->charges = [fn () => Http::response([], 503), fn () => Http::response(Answers::refusal('SessionId is already used!'), 400)];
        $fake->sessionUsed = true;
        $fake->attemptId = null;

        $start = app(PaymentsApi::class)->charge($id);

        expect($start->state)->toBe(PaymentState::Unknown)
            ->and(attemptsEvents(PaymentNotTaken::class))->toBe(0);

        // The first check asks about the session, and finds the payment it made.
        $fake->paid();
        attemptsAt('+5 minutes');
        attemptsCheck($id);

        $row = attemptsRow($id);
        expect([$row->state, $row->invoice_id, $row->gateway_payment_id])->toBe(['SUCCEEDED', FakeMyFatoorah::INVOICE_ID, FakeMyFatoorah::PAYMENT_ID])
            ->and(attemptsEvents(PaymentSucceeded::class))->toBe(1);
    });

    it('marks a refused key, and ends the attempt with nothing charged', function () {
        ['fake' => $fake, 'id' => $id] = attemptsPrepared();
        $fake->charges = [fn () => Http::response(['IsSuccess' => false, 'Message' => 'Unauthorized'], 401)];

        $start = app(PaymentsApi::class)->charge($id);

        expect($start->state)->toBe(PaymentState::Failed)
            ->and(DB::table('payments.gateway_keys')->whereNotNull('rejected_at')->count())->toBe(1)
            ->and(attemptsEvents(PaymentNotTaken::class))->toBe(1);
    });

    it('refuses to send a charge after its window: never charged', function () {
        ['fake' => $fake, 'id' => $id] = attemptsPrepared();
        attemptsAt('+5 minutes');

        $start = app(PaymentsApi::class)->charge($id);

        expect($fake->count('POST', '/v3/payments'))->toBe(0)
            ->and($start->state)->toBe(PaymentState::Expired)
            ->and($start->nothingCharged)->toBeTrue()
            ->and(attemptsRow($id)->state)->toBe('ABANDONED')
            ->and(attemptsEvents(PaymentNotTaken::class))->toBe(1);
    });

    it('answers an attempt that is not there', function () {
        expect(fn () => app(PaymentsApi::class)->charge(strtolower((string) Str::ulid())))->toThrow(AttemptNotFound::class)
            ->and(fn () => app(PaymentsApi::class)->abandon(strtolower((string) Str::ulid())))->toThrow(AttemptNotFound::class);
    });
});

describe('abandoning (rule 15)', function () {
    it('ends a prepared attempt, again if asked again, with no event: Sales releases it itself', function () {
        ['fake' => $fake, 'id' => $id] = attemptsPrepared();

        expect(app(PaymentsApi::class)->abandon($id))->toBeTrue()
            ->and(app(PaymentsApi::class)->abandon($id))->toBeTrue()
            ->and(attemptsRow($id)->state)->toBe('ABANDONED')
            ->and(attemptsRow($id)->nothing_charged)->toBeTrue()
            ->and(attemptsRow($id)->next_check_at)->toBeNull()
            ->and(attemptsEvents(PaymentNotTaken::class))->toBe(0);

        app(PaymentsApi::class)->charge($id);

        expect($fake->count('POST', '/v3/payments'))->toBe(0);
    });

    it('loses to the claim: nothing released once the charge is sent (§8 #43)', function () {
        ['id' => $id] = attemptsCharged();

        expect(app(PaymentsApi::class)->abandon($id))->toBeFalse()
            ->and(attemptsRow($id)->state)->toBe('OPEN');
    });

    it('lets an open attempt end, then another start for the owner', function () {
        ['store' => $store, 'id' => $id] = attemptsPrepared();
        app(PaymentsApi::class)->abandon($id);

        $next = app(PaymentsApi::class)->prepare(attemptsRequest($store, attemptsSession($store, 'quote-2'), ['quoteId' => 'quote-2']));

        expect($next)->not->toBe($id);
    });
});

describe('the answers (rules 1, 5, 6, 8)', function () {
    it('takes a success once, with what was paid, and tells Sales once (§8 #1, #14)', function () {
        ['fake' => $fake, 'store' => $store, 'id' => $id] = attemptsCharged();
        $fake->paid();
        attemptsAt('+1 minute');

        expect(app(PaymentsApi::class)->refresh(ATTEMPTS_TRY)->state)->toBe(PaymentState::Succeeded);

        attemptsAt('+2 minutes');
        app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);
        attemptsHandleNotice($store, attemptsNotice(attempt: $id));

        $row = attemptsRow($id);
        $event = json_decode((string) DB::table('public.outbox_messages')->where('type', PaymentSucceeded::class)->value('payload'), true);
        $payment = app(PaymentsApi::class)->payment((string) $event['paymentId']);

        expect([$row->state, (int) $row->paid_minor, $row->mismatch, $row->paid_twice, $row->needs_attention, $row->next_check_at])->toBe(['SUCCEEDED', 118050, false, false, false, null])
            ->and(CarbonImmutable::parse($row->succeeded_at)->toIso8601ZuluString())->toBe('2026-10-28T19:33:08Z')
            ->and(DB::table('payments.transactions')->count())->toBe(1)
            ->and(attemptsEvents(PaymentSucceeded::class))->toBe(1)
            ->and($event)->toMatchArray(['orderId' => ATTEMPTS_ORDER, 'storeId' => $store])
            ->and([$payment?->attemptId, $payment?->ownerId, $payment?->ownerKind, $payment?->orderId, $payment?->amount->minorUnits, $payment?->expected->minorUnits, $payment?->method, $payment?->reference, $payment?->mode, $payment?->succeeded, $payment?->mismatch, $payment?->paidTwice])
            ->toBe([$id, ATTEMPTS_TRY, PaymentOwnerKind::Try, ATTEMPTS_ORDER, 118050, 118050, 'Card', FakeMyFatoorah::PAYMENT_ID, PaymentMode::Test, true, false, false]);
    });

    it('names no card and no customer in what it keeps', function () {
        ['fake' => $fake, 'id' => $id] = attemptsCharged();
        $fake->paid();
        attemptsAt('+1 minute');
        app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        $kept = (string) json_encode([DB::table('payments.transactions')->get(), DB::table('payments.calls')->get()]);

        foreach (['446404xxxxxx0007', 'a-card-token', 'a-pan-hash', 'test test', '88.253.45.0', 'buyer@example.test', 'A Buyer', 'sk_key_ABC123'] as $private) {
            expect($kept)->not->toContain($private);
        }

        expect(DB::table('payments.transactions')->value('card_brand'))->toBe('Visa')
            ->and(DB::table('payments.calls')->where('attempt_id', $id)->count())->toBeGreaterThan(0);
    });

    it('releases at once on a decline, with the bank\'s code (§8 #2)', function () {
        ['fake' => $fake, 'id' => $id] = attemptsCharged();
        $fake->declined();
        attemptsAt('+1 minute');

        expect(app(PaymentsApi::class)->refresh(ATTEMPTS_TRY)->state)->toBe(PaymentState::Failed);

        $event = json_decode((string) DB::table('public.outbox_messages')->where('type', PaymentFailed::class)->value('payload'), true);
        $payment = app(PaymentsApi::class)->payment((string) $event['paymentId']);

        expect(attemptsRow($id)->state)->toBe('FAILED')
            ->and(attemptsRow($id)->next_check_at)->toBeNull()
            ->and(attemptsEvents(PaymentFailed::class))->toBe(1)
            ->and([$payment?->succeeded, $payment?->failureCode])->toBe([false, 'MF003']);
    });

    it('lets a success win over a failure, and a failure change nothing after a success (§8 #17)', function () {
        ['fake' => $fake, 'id' => $id] = attemptsCharged();
        $fake->declined();
        attemptsAt('+1 minute');
        app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        $fake->paid();
        attemptsHandleNotice(attemptsRow($id)->store_id, attemptsNotice(attempt: $id));

        expect(attemptsRow($id)->state)->toBe('SUCCEEDED')
            ->and(attemptsRow($id)->needs_attention)->toBeTrue()
            ->and(DB::table('payments.transactions')->count())->toBe(1)
            ->and(DB::table('payments.transactions')->value('succeeded'))->toBeTrue()
            ->and(attemptsEvents(PaymentSucceeded::class))->toBe(1);

        $fake->declined();
        attemptsHandleNotice(attemptsRow($id)->store_id, attemptsNotice(attempt: $id));

        expect(attemptsRow($id)->state)->toBe('SUCCEEDED')
            ->and(attemptsEvents(PaymentFailed::class))->toBe(1);
    });

    it('records a different amount as it really was, flagged, and still tells Sales (§8 #18)', function () {
        ['fake' => $fake, 'id' => $id] = attemptsCharged();
        $fake->paid('1000.00');
        attemptsAt('+1 minute');
        app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        $row = attemptsRow($id);
        $payment = app(PaymentsApi::class)->payment((string) DB::table('payments.transactions')->value('id'));

        expect([$row->state, (int) $row->paid_minor, $row->mismatch, $row->needs_attention])->toBe(['SUCCEEDED', 100000, true, true])
            ->and([$payment?->amount->minorUnits, $payment?->expected->minorUnits, $payment?->mismatch])->toBe([100000, 118050, true])
            ->and(attemptsEvents(PaymentSucceeded::class))->toBe(1);
    });

    it('flags an amount it cannot read exactly, rather than rounding it', function () {
        ['fake' => $fake, 'id' => $id] = attemptsCharged();
        $fake->paid('1180.505');
        attemptsAt('+1 minute');
        app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        expect(attemptsRow($id)->paid_minor)->toBeNull()
            ->and(attemptsRow($id)->mismatch)->toBeTrue()
            ->and(DB::table('payments.transactions')->value('amount_text'))->toBe('1180.505');
    });

    it('keeps a different currency in Payments, flagged, and never sends it to Sales (§8 #18, #38)', function () {
        ['fake' => $fake, 'store' => $store, 'id' => $id] = attemptsCharged();
        $fake->paid();
        $fake->displayCurrency = 'USD';
        attemptsAt('+1 minute');
        app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        $row = attemptsRow($id);
        expect([$row->state, $row->mismatch, $row->needs_attention])->toBe(['SUCCEEDED', true, true])
            ->and(DB::table('payments.transactions')->value('published'))->toBeFalse()
            ->and(attemptsEvents(PaymentSucceeded::class))->toBe(0)
            ->and(attemptsEvents(PaymentNotAccepted::class))->toBe(1)
            ->and(json_decode((string) DB::table('public.outbox_messages')->where('type', PaymentNotAccepted::class)->value('payload'), true))->toMatchArray(['attemptId' => $id, 'ownerId' => ATTEMPTS_TRY, 'orderId' => ATTEMPTS_ORDER, 'storeId' => $store]);
    });

    it('checks the account\'s own currency in live, but not on the test server, which answers in KWD (gap 9.1)', function (string $mode, string $base, int $accepted) {
        Http::preventStrayRequests(false);
        ['fake' => $fake] = attemptsCharged($mode);
        $fake->paid();
        $fake->baseCurrency = $base;
        attemptsAt('+1 minute');
        app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        expect(attemptsEvents(PaymentSucceeded::class))->toBe($accepted)
            ->and(attemptsEvents(PaymentNotAccepted::class))->toBe(1 - $accepted);
    })->with([
        'test, KWD' => ['TEST', 'KWD', 1],
        'live, SAR' => ['LIVE', 'SAR', 1],
        'live, KWD' => ['LIVE', 'KWD', 0],
    ]);

    it('names the method plainly: a Mada card answers as VISA/MASTER (gap 9.2)', function (string $gateway, string $plain) {
        ['fake' => $fake, 'id' => $id] = attemptsCharged();
        $fake->paid();
        $fake->statusAnswer = function () use ($id, $gateway) {
            $data = Answers::paymentData('SUCCESS', 'PAID');
            $data['Invoice']['ExternalIdentifier'] = $id;
            $data['Invoice']['Id'] = FakeMyFatoorah::INVOICE_ID;
            $data['Transaction']['PaymentId'] = FakeMyFatoorah::PAYMENT_ID;
            $data['Transaction']['PaymentMethod'] = $gateway;
            $data['Amount'] = ['BaseCurrency' => 'SAR', 'DisplayCurrency' => 'SAR', 'ValueInDisplayCurrency' => '1180.50'];

            return Http::response(['IsSuccess' => true, 'Data' => $data]);
        };
        attemptsAt('+1 minute');
        app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        expect(DB::table('payments.transactions')->value('method'))->toBe($plain);
    })->with([
        ['VISA/MASTER', 'Card'],
        ['MADA', 'Card'],
        ['Apple Pay', 'Apple Pay'],
        ['APPLEPAY', 'Apple Pay'],
        ['STC Pay', 'STC Pay'],
    ]);

    it('records a second success as paid twice, a refund due, and tells Sales of it (§8 #20)', function () {
        ['fake' => $fake, 'store' => $store, 'id' => $id] = attemptsCharged();
        $fake->paid();
        attemptsAt('+1 minute');
        app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        attemptsHandleNotice($store, attemptsNotice('07077320290391998274', $id));

        $row = attemptsRow($id);
        expect([$row->state, $row->paid_twice, $row->needs_attention, $row->gateway_payment_id, (int) $row->paid_minor])->toBe(['SUCCEEDED', true, true, FakeMyFatoorah::PAYMENT_ID, 118050])
            ->and(DB::table('payments.transactions')->count())->toBe(2)
            ->and(DB::table('payments.transactions')->where('paid_twice', true)->value('gateway_payment_id'))->toBe('07077320290391998274')
            ->and(attemptsEvents(PaymentSucceeded::class))->toBe(2);
    });

    it('applies an answer naming another attempt or invoice to nothing (rule 7, §8 #16)', function (Closure $change) {
        ['fake' => $fake, 'id' => $id] = attemptsCharged();
        $fake->paid();
        $change($fake);
        attemptsAt('+1 minute');

        expect(app(PaymentsApi::class)->refresh(ATTEMPTS_TRY)->state)->toBe(PaymentState::Unknown)
            ->and(attemptsRow($id)->state)->toBe('UNKNOWN')
            ->and(DB::table('payments.transactions')->count())->toBe(0)
            ->and(attemptsEvents(PaymentSucceeded::class))->toBe(0);
    })->with([
        'another attempt' => [fn (FakeMyFatoorah $fake) => $fake->attemptId = strtolower((string) Str::ulid())],
        'another invoice' => [fn (FakeMyFatoorah $fake) => $fake->invoiceId = '999'],
    ]);

    it('keeps the same payment number apart in test and live (§8 #31)', function () {
        ['fake' => $fake, 'store' => $store] = attemptsCharged();
        $fake->paid();
        attemptsAt('+1 minute');
        app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        app(ChangeKeysHandler::class)->handle(new ChangeKeys($store, 'LIVE', 'sk_live_key_ABC', 'whsec_live', '2027-12-31'));
        app(SwitchModeHandler::class)->handle(new SwitchMode($store, 'LIVE'));
        $live = app(PaymentsApi::class)->prepare(attemptsRequest($store, attemptsSession($store, 'quote-2'), ['ownerId' => 'try-2', 'quoteId' => 'quote-2']));
        app(PaymentsApi::class)->charge($live);
        attemptsAt('+3 minutes');
        app(PaymentsApi::class)->refresh('try-2');

        expect(DB::table('payments.transactions')->orderBy('mode')->pluck('mode')->all())->toBe(['LIVE', 'TEST'])
            ->and(attemptsEvents(PaymentSucceeded::class))->toBe(2);
    });
});

describe('the timers (rule 15, §1.7, §9.8)', function () {
    it('holds through the window and the wait while the bank is still answering, and releases at the hold limit (§8 #36)', function () {
        ['id' => $id] = attemptsCharged();

        foreach (['+5 minutes', '+7 minutes', '+10 minutes'] as $when) {
            attemptsAt($when);
            attemptsCheck($id);

            expect(attemptsRow($id)->state)->toBe('OPEN')
                ->and(attemptsEvents(PaymentNotTaken::class))->toBe(0);
        }

        attemptsAt('+15 minutes');
        attemptsCheck($id);

        $state = app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        expect(attemptsRow($id)->state)->toBe('EXPIRED')
            ->and($state->state)->toBe(PaymentState::Expired)
            ->and($state->nothingCharged)->toBeFalse()
            ->and($state->holdEndsAt?->format('H:i'))->toBe('12:15')
            ->and(attemptsEvents(PaymentNotTaken::class))->toBe(1);
    });

    it('still finds a success at the very end of the wait (the owner\'s case, §8 #9)', function () {
        ['fake' => $fake, 'id' => $id] = attemptsCharged();
        attemptsAt('+5 minutes');
        attemptsCheck($id);

        $fake->paid();
        attemptsAt('+7 minutes');
        attemptsCheck($id);

        expect(attemptsRow($id)->state)->toBe('SUCCEEDED')
            ->and(attemptsRow($id)->needs_attention)->toBeFalse()
            ->and(attemptsEvents(PaymentSucceeded::class))->toBe(1)
            ->and(attemptsEvents(PaymentNotTaken::class))->toBe(0);
    });

    it('releases at the hold limit even when MyFatoorah cannot be reached (§1.7)', function () {
        ['fake' => $fake, 'id' => $id] = attemptsCharged();
        $fake->statusAnswer = fn () => Http::response([], 503);

        attemptsAt('+5 minutes');
        attemptsCheck($id);

        expect(attemptsRow($id)->state)->toBe('UNKNOWN')
            ->and(attemptsEvents(PaymentNotTaken::class))->toBe(0)
            ->and(app(PaymentsApi::class)->refresh(ATTEMPTS_TRY)->state)->toBe(PaymentState::Unknown);

        attemptsAt('+15 minutes');
        attemptsCheck($id);

        expect(attemptsRow($id)->state)->toBe('EXPIRED')
            ->and(attemptsEvents(PaymentNotTaken::class))->toBe(1)
            ->and(attemptsRow($id)->needs_attention)->toBeFalse();

        attemptsAt('+20 minutes');
        attemptsCheck($id);

        expect(attemptsRow($id)->needs_attention)->toBeTrue();
    });

    it('goes on looking after the release, so a late success is found: no second release, a refund due (§8 #19, #37)', function () {
        ['fake' => $fake, 'id' => $id] = attemptsCharged();
        attemptsAt('+15 minutes');
        attemptsCheck($id);

        $fake->paid();
        attemptsAt('+1 hour');
        attemptsCheck($id);

        $row = attemptsRow($id);
        expect([$row->state, $row->needs_attention, $row->next_check_at])->toBe(['SUCCEEDED', true, null])
            ->and(attemptsEvents(PaymentSucceeded::class))->toBe(1)
            ->and(attemptsEvents(PaymentNotTaken::class))->toBe(1);
    });

    it('stops looking 24 hours after the charge, and tells staff (gap 10.4)', function () {
        ['id' => $id] = attemptsCharged();
        attemptsAt('+15 minutes');
        attemptsCheck($id);
        attemptsAt('+23 hours');
        attemptsCheck($id);

        expect(attemptsRow($id)->next_check_at)->not->toBeNull()
            ->and(attemptsRow($id)->needs_attention)->toBeFalse();

        attemptsAt('+24 hours');
        attemptsCheck($id);

        expect(attemptsRow($id)->next_check_at)->toBeNull()
            ->and(attemptsRow($id)->needs_attention)->toBeTrue();
    });

    it('ends an attempt a crash left unsent at its first check, with no call (§8 #34)', function () {
        ['fake' => $fake, 'id' => $id] = attemptsPrepared();
        $before = count($fake->calls);

        attemptsAt('+5 minutes');
        attemptsCheck($id);

        expect(count($fake->calls))->toBe($before)
            ->and(attemptsRow($id)->state)->toBe('ABANDONED')
            ->and(attemptsRow($id)->nothing_charged)->toBeTrue()
            ->and(attemptsEvents(PaymentNotTaken::class))->toBe(1);
    });

    it('settles a claimed charge that never arrived through its session, at the wait\'s end: "no money was taken"', function () {
        ['fake' => $fake, 'id' => $id] = attemptsPrepared();
        $fake->charges = [fn () => Http::response([], 503), fn () => Http::response([], 503), fn () => Http::response([], 503)];
        app(PaymentsApi::class)->charge($id);

        attemptsAt('+5 minutes');
        attemptsCheck($id);

        expect(attemptsRow($id)->state)->toBe('UNKNOWN')
            ->and(attemptsEvents(PaymentNotTaken::class))->toBe(0);

        attemptsAt('+7 minutes');
        attemptsCheck($id);

        $state = app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        expect($state->state)->toBe(PaymentState::Expired)
            ->and($state->nothingCharged)->toBeTrue()
            ->and(attemptsRow($id)->next_check_at)->toBeNull()
            ->and($fake->count('GET', '/v3/sessions/'))->toBe(2)
            ->and(attemptsEvents(PaymentNotTaken::class))->toBe(1);
    });

    it('asks nothing at all with no attempt open (§8 #30)', function () {
        $fake = FakeMyFatoorah::install();

        app(AttemptSweep::class)->sweep();

        expect($fake->calls)->toBe([]);
    });

    it('asks only when a check is due, and never again once it has its answer (§8 #30)', function () {
        ['fake' => $charged, 'id' => $id] = attemptsCharged();
        $before = count($charged->calls);
        attemptsCheck($id);

        expect(count($charged->calls))->toBe($before);

        $charged->paid();
        attemptsAt('+5 minutes');
        attemptsCheck($id);
        $after = count($charged->calls);
        attemptsAt('+7 minutes');
        attemptsCheck($id);
        app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        expect(count($charged->calls))->toBe($after);
    });

    it('gives a page an answer up to 10 seconds old, rather than a new call', function () {
        ['fake' => $fake] = attemptsCharged();
        attemptsAt('+1 minute');
        app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);
        $calls = $fake->count('GET', '/v3/payments/');

        attemptsAt('+1 minute 9 seconds');
        app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        expect($fake->count('GET', '/v3/payments/'))->toBe($calls);

        attemptsAt('+1 minute 11 seconds');
        app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        expect($fake->count('GET', '/v3/payments/'))->toBe($calls + 1);
    });

    it('allows one status call at a time per attempt', function () {
        ['fake' => $fake, 'id' => $id] = attemptsCharged();
        DB::table('payments.attempts')->where('id', $id)->update(['checking_until' => CarbonImmutable::now()->addMinute()]);
        attemptsAt('+5 seconds');
        $calls = $fake->count('GET', '/v3/payments/');

        app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        expect($fake->count('GET', '/v3/payments/'))->toBe($calls);

        attemptsAt('+2 minutes');
        app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        expect($fake->count('GET', '/v3/payments/'))->toBe($calls + 1)
            ->and(attemptsRow($id)->checking_until)->toBeNull();
    });

    it('books each next check through the queue, and the hourly look finds a lost one', function () {
        Queue::fake();
        ['id' => $id] = attemptsCharged();

        // After the commit, so a try Sales rolls back leaves no job behind.
        Queue::assertPushed(CheckAttemptJob::class, fn (CheckAttemptJob $job): bool => $job->attemptId === $id && $job->afterCommit === true);

        attemptsAt('+2 hours');
        Queue::fake();
        app(AttemptSweep::class)->sweep();

        Queue::assertPushed(CheckAttemptJob::class, 1);
    });

    it('leaves nothing behind of a try Sales rolled back', function () {
        $fake = FakeMyFatoorah::install();
        $store = attemptsGateway();
        $session = attemptsSession($store);

        try {
            DB::transaction(function () use ($store, $session): void {
                app(PaymentsApi::class)->prepare(attemptsRequest($store, $session));

                throw new LogicException('Sales changed its mind');
            });
        } catch (LogicException) {
            // The rollback is the point.
        }

        expect(DB::table('payments.attempts')->count())->toBe(0)
            ->and(DB::table('payments.sessions')->where('id', $session)->value('used_by'))->toBeNull()
            ->and($fake->count('POST', '/v3/payments'))->toBe(0);
    });
});

describe('a notice (rule 1, §1.1 rule 7)', function () {
    it('makes us ask, and believes nothing it says: the gateway says it is still going (§8 #15)', function () {
        ['fake' => $fake, 'store' => $store, 'id' => $id] = attemptsCharged();

        attemptsHandleNotice($store, attemptsNotice(attempt: $id));

        expect($fake->count('GET', '/v3/payments/'))->toBe(1)
            ->and(attemptsRow($id)->state)->toBe('OPEN')
            ->and(attemptsEvents(PaymentSucceeded::class))->toBe(0);
    });

    it('finds a success when the page never came back (§8 #13)', function () {
        ['fake' => $fake, 'store' => $store, 'id' => $id] = attemptsCharged();
        $fake->paid();

        $notice = attemptsHandleNotice($store, attemptsNotice(attempt: $id));

        expect(attemptsRow($id)->state)->toBe('SUCCEEDED')
            ->and(DB::table('payments.notices')->where('id', $notice)->value('handled_at'))->not->toBeNull();
    });

    it('asks once for a payment already written down, however often it is noticed', function () {
        ['fake' => $fake, 'store' => $store, 'id' => $id] = attemptsCharged();
        $fake->paid();
        attemptsHandleNotice($store, attemptsNotice(attempt: $id));
        $calls = $fake->count('GET', '/v3/payments/');

        attemptsHandleNotice($store, attemptsNotice(attempt: $id));
        attemptsHandleNotice($store, attemptsNotice(attempt: $id));

        expect($fake->count('GET', '/v3/payments/'))->toBe($calls)
            ->and(attemptsEvents(PaymentSucceeded::class))->toBe(1);
    });

    it('finds its attempt by the invoice when it names no attempt', function () {
        ['fake' => $fake, 'store' => $store, 'id' => $id] = attemptsCharged();
        $fake->paid();

        attemptsHandleNotice($store, attemptsNotice());

        expect(attemptsRow($id)->state)->toBe('SUCCEEDED');
    });

    it('is applied to nothing when it is another store\'s or mode\'s, names another invoice, or names nothing we made', function (Closure $payload) {
        ['fake' => $fake, 'store' => $store, 'id' => $id] = attemptsCharged();
        $fake->paid();
        $calls = $fake->count('GET', '/v3/payments/');

        [$noticeStore, $mode, $data] = $payload($store, $id);
        $notice = attemptsHandleNotice($noticeStore, $data, $mode);

        expect($fake->count('GET', '/v3/payments/'))->toBe($calls)
            ->and(attemptsRow($id)->state)->toBe('OPEN')
            ->and(DB::table('payments.notices')->where('id', $notice)->value('handled_at'))->not->toBeNull();
    })->with([
        'another store' => [fn (string $store, string $id) => [Fx::storeId('eg'), 'TEST', attemptsNotice(attempt: $id)]],
        'another mode' => [fn (string $store, string $id) => [$store, 'LIVE', attemptsNotice(attempt: $id)]],
        'another invoice' => [fn (string $store, string $id) => [$store, 'TEST', attemptsNotice(attempt: $id, invoice: '999')]],
        'nothing we made' => [fn (string $store, string $id) => [$store, 'TEST', attemptsNotice(attempt: strtolower((string) Str::ulid()), invoice: '999')]],
        'names nothing' => [fn (string $store, string $id) => [$store, 'TEST', ['Event' => ['Code' => 1, 'Reference' => 'r'], 'Data' => []]]],
    ]);

    it('asks nothing about an attempt that was never charged', function () {
        ['fake' => $fake, 'store' => $store, 'id' => $id] = attemptsPrepared();
        app(PaymentsApi::class)->abandon($id);
        $calls = count($fake->calls);

        attemptsHandleNotice($store, attemptsNotice(attempt: $id));

        expect(count($fake->calls))->toBe($calls)
            ->and(attemptsRow($id)->state)->toBe('ABANDONED');
    });

    it('is queued as it comes in, once, and the hourly look picks up one whose job was lost', function () {
        Queue::fake();
        $store = Fx::storeId('sa');
        app(ChangeKeysHandler::class)->handle(new ChangeKeys($store, 'TEST', 'sk_key_ABC123', 'whsec_secret_1', '2027-12-31'));
        $notice = Answers::notice('whsec_secret_1');
        $post = fn () => call('POST', "/payments/notice/{$store}/test", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_MYFATOORAH_SIGNATURE' => $notice['signature']], $notice['body']);

        $post()->assertStatus(200);
        $post()->assertStatus(200);

        Queue::assertPushed(HandleNoticeJob::class, 1);

        attemptsAt('+10 minutes');
        Queue::fake();
        app(AttemptSweep::class)->sweep();

        Queue::assertPushed(HandleNoticeJob::class, 1);
    });
});

describe('a key changed while attempts are open (rule 14, §8 #32)', function () {
    it('closes each attempt with the key it was made with', function () {
        ['fake' => $fake, 'store' => $store, 'id' => $id] = attemptsCharged();
        app(ChangeKeysHandler::class)->handle(new ChangeKeys($store, 'TEST', 'sk_key_NEW123', 'whsec_secret_2', '2027-12-31'));
        $fake->paid();
        attemptsAt('+1 minute');

        app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        $request = attemptsLastRequest();

        expect($request->header('Authorization'))->toBe(['Bearer sk_key_ABC123'])
            ->and(attemptsRow($id)->state)->toBe('SUCCEEDED');
    });

    it('asks with the new key when the old one no longer answers, telling nobody of the old one (§1.2)', function () {
        ['fake' => $fake, 'store' => $store, 'id' => $id] = attemptsCharged();
        app(ChangeKeysHandler::class)->handle(new ChangeKeys($store, 'TEST', 'sk_key_NEW123', 'whsec_secret_2', '2027-12-31'));
        $fake->paid();
        $answered = false;
        $fake->statusAnswer = function () use (&$answered, $fake, $id) {
            if (! $answered) {
                $answered = true;

                return Http::response(['IsSuccess' => false, 'Message' => 'Unauthorized'], 401);
            }

            $fake->statusAnswer = null;
            $data = Answers::paymentData('SUCCESS', 'PAID');
            $data['Invoice']['Id'] = FakeMyFatoorah::INVOICE_ID;
            $data['Invoice']['ExternalIdentifier'] = $id;
            $data['Transaction']['PaymentId'] = FakeMyFatoorah::PAYMENT_ID;
            $data['Amount'] = ['BaseCurrency' => 'SAR', 'DisplayCurrency' => 'SAR', 'ValueInDisplayCurrency' => '1180.50'];

            return Http::response(['IsSuccess' => true, 'Data' => $data]);
        };
        attemptsAt('+1 minute');

        expect(app(PaymentsApi::class)->refresh(ATTEMPTS_TRY)->state)->toBe(PaymentState::Succeeded)
            ->and(DB::table('payments.gateway_keys')->whereNotNull('rejected_at')->count())->toBe(0);
    });

    it('stays unknown when a refused current key has no other to ask with, and marks it', function () {
        ['fake' => $fake, 'id' => $id] = attemptsCharged();
        $fake->statusAnswer = fn () => Http::response(['IsSuccess' => false, 'Message' => 'Unauthorized'], 401);
        attemptsAt('+1 minute');

        expect(app(PaymentsApi::class)->refresh(ATTEMPTS_TRY)->state)->toBe(PaymentState::Unknown)
            ->and(attemptsRow($id)->state)->toBe('UNKNOWN')
            ->and(DB::table('payments.gateway_keys')->whereNotNull('rejected_at')->count())->toBe(1);
    });

    it('goes on checking while the gateway is switched off (§9.5 #4)', function () {
        ['fake' => $fake, 'store' => $store, 'id' => $id] = attemptsCharged();
        app(SwitchGatewayHandler::class)->handle(new SwitchGateway($store, false));
        $fake->paid();
        attemptsAt('+5 minutes');
        attemptsCheck($id);

        expect(attemptsRow($id)->state)->toBe('SUCCEEDED');
    });
});

describe('what the page reads', function () {
    it('says none for an owner with no attempt, and gives no answer for what is no transaction', function () {
        $state = app(PaymentsApi::class)->refresh('no-such-owner');

        expect([$state->state, $state->attemptId, $state->windowEndsAt])->toBe([PaymentState::None, null, null])
            ->and(app(PaymentsApi::class)->payment('nonsense'))->toBeNull()
            ->and(app(PaymentsApi::class)->payment('999999'))->toBeNull();
    });

    it('reads a prepared attempt as open, with no call', function () {
        ['fake' => $fake, 'id' => $id] = attemptsPrepared();
        $calls = count($fake->calls);

        $state = app(PaymentsApi::class)->refresh(ATTEMPTS_TRY);

        expect([$state->state, $state->attemptId])->toBe([PaymentState::Open, $id])
            ->and(count($fake->calls))->toBe($calls);
    });

    it('reads the owner\'s newest attempt', function () {
        ['store' => $store, 'id' => $id] = attemptsPrepared();
        app(PaymentsApi::class)->abandon($id);
        attemptsAt('+1 minute');
        $next = app(PaymentsApi::class)->prepare(attemptsRequest($store, attemptsSession($store, 'quote-2'), ['quoteId' => 'quote-2']));

        expect(app(PaymentsApi::class)->refresh(ATTEMPTS_TRY)->attemptId)->toBe($next);
    });
});

it('clears the customer\'s details from their attempts when the account is anonymized, and only theirs (rule 13)', function () {
    ['store' => $store, 'id' => $id] = attemptsPrepared();
    $other = app(PaymentsApi::class)->prepare(new PayRequestDto(
        'try-2', PaymentOwnerKind::Try, 'order-2', 'quote-2', StoreId::fromString($store), 'buyer-2',
        app(PaymentsApi::class)->openSession(new SessionRequestDto(StoreId::fromString($store), 'quote-2', 'buyer-2', Money::of(100, 'SAR'), 'en', CarbonImmutable::now()->addMinutes(30)))->sessionId,
        Money::of(100, 'SAR'), 'Another', 'another@example.test', null, 'en', 'https://shop.example.test/return',
    ));

    app(ClearCustomerFromAttempts::class)->handle(new CustomerAnonymized((string) Str::uuid(), ATTEMPTS_BUYER, CarbonImmutable::now()->toDateTimeImmutable()));

    $row = attemptsRow($id);
    expect([$row->customer_name, $row->customer_email, $row->customer_phone])->toBe([null, null, null])
        ->and(attemptsRow($other)->customer_email)->toBe('another@example.test');
});
