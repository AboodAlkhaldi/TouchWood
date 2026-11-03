<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Modules\Payments\Application\Attempt\AttemptChecks;
use Modules\Payments\Application\Attempt\PaymentLogPruning;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Command\SwitchMode\SwitchMode;
use Modules\Payments\Application\Command\SwitchMode\SwitchModeHandler;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Domain\Exception\AmountChanged;
use Modules\Payments\Domain\Exception\InvalidPaymentsInput;
use Modules\Payments\Domain\Exception\NoOnlinePayment;
use Modules\Payments\Domain\Exception\PaymentInProgress;
use Modules\Payments\Domain\Exception\RequestNotOpen;
use Modules\Payments\Domain\Model\PaymentRequest;
use Modules\Payments\Domain\Repository\PaymentRequests;
use Modules\Payments\Infrastructure\Queue\PrunePaymentLogJob;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\PaymentRequestDto;
use Modules\Payments\Public\Dto\PaymentRequestStateDto;
use Modules\Payments\Public\Dto\PayRequestDto;
use Modules\Payments\Public\Dto\SessionRequestDto;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Payments\Public\Enums\PaymentOwnerKind;
use Modules\Payments\Public\Enums\PaymentRequestState;
use Modules\Payments\Public\Enums\PaymentState;
use Modules\Payments\Public\Events\PaymentNotAccepted;
use Modules\Payments\Public\Events\PaymentRequested;
use Modules\Payments\Public\Events\PaymentSucceeded;
use Modules\Sales\Infrastructure\Listener\WithdrawRequestWhenCancelled;
use Modules\Sales\Public\Events\OrderCancelled;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Payments\Support\FakeMyFatoorah;

use function Pest\Laravel\seed;

/*
| A staff request for the rest of an order (payments.md §1.5, §4; its scenario in §8 #22): one open per order, 60
| minutes that wait for a payment in progress, and the same money never paid twice.
*/

uses(RefreshDatabase::class);

const REQUESTS_NOW = '2027-01-01T12:00:00Z';

const REQUESTS_ORDER = '01arz3ndektsv4rrffq69g5fav';

const REQUESTS_BUYER = '01arz3ndektsv4rrffq69g5faw';

const REQUESTS_STAFF = '01arz3ndektsv4rrffq69g5fax';

beforeEach(function () {
    seed(PlatformSeeder::class);
    app()->instance(KeyTester::class, new class implements KeyTester
    {
        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void {}
    });
    Sleep::fake();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    requestsAt('+0 minutes');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

function requestsAt(string $when): void
{
    $moment = CarbonImmutable::parse(REQUESTS_NOW)->modify($when);
    CarbonImmutable::setTestNow($moment);
    Carbon::setTestNow($moment);
}

function requestsGateway(string $mode = 'TEST'): string
{
    $store = Fx::storeId('sa');
    app(ChangeKeysHandler::class)->handle(new ChangeKeys($store, $mode, 'sk_key_ABC123', 'whsec_secret_1', '2030-12-31'));
    app(SwitchGatewayHandler::class)->handle(new SwitchGateway($store, true));

    return $store;
}

function requestsAsk(string $store, int $amount = 4500, PaymentMode $mode = PaymentMode::Test, string $order = REQUESTS_ORDER): PaymentRequestStateDto
{
    return app(PaymentsApi::class)->requestPayment(new PaymentRequestDto($order, 'SA-0001', StoreId::fromString($store), Money::of($amount, 'SAR'), REQUESTS_BUYER, $mode), REQUESTS_STAFF);
}

function requestsSession(string $store, string $requestId, int $amount = 4500, string $buyer = REQUESTS_BUYER): string
{
    return app(PaymentsApi::class)->openSession(new SessionRequestDto(StoreId::fromString($store), $requestId, $buyer, Money::of($amount, 'SAR'), 'en', CarbonImmutable::now()->addMinutes(30)))->sessionId;
}

function requestsPay(string $store, string $requestId, string $session, int $amount = 4500, string $order = REQUESTS_ORDER, string $buyer = REQUESTS_BUYER): string
{
    return app(PaymentsApi::class)->prepare(new PayRequestDto(
        $requestId, PaymentOwnerKind::Request, $order, null, StoreId::fromString($store), $buyer, $session, Money::of($amount, 'SAR'),
        'A Buyer', 'buyer@example.test', '+966500000000', 'en', 'https://shop.example.test/return',
    ));
}

/**
 * @return array{fake: FakeMyFatoorah, store: string, request: PaymentRequestStateDto, attempt: string}
 */
function requestsCharged(): array
{
    $fake = FakeMyFatoorah::install();
    $store = requestsGateway();
    $request = requestsAsk($store);
    $attempt = requestsPay($store, $request->requestId, requestsSession($store, $request->requestId));
    app(PaymentsApi::class)->charge($attempt);

    return ['fake' => $fake, 'store' => $store, 'request' => $request, 'attempt' => $attempt];
}

function requestsState(string $id): string
{
    return (string) DB::table('payments.requests')->where('id', $id)->value('state');
}

function requestsEvents(string $event): int
{
    return DB::table('public.outbox_messages')->where('type', $event)->count();
}

describe('asking', function () {
    it('opens a request for 60 minutes, and tells of it once', function () {
        $store = requestsGateway();

        $request = requestsAsk($store);

        expect($request->state)->toBe(PaymentRequestState::Open)
            ->and($request->amount->minorUnits)->toBe(4500)
            ->and($request->requestedBy)->toBe(REQUESTS_STAFF)
            ->and($request->mode)->toBe(PaymentMode::Test)
            ->and(CarbonImmutable::instance($request->expiresAt)->equalTo(CarbonImmutable::parse(REQUESTS_NOW)->addMinutes(60)))->toBeTrue()
            ->and(DB::table('payments.requests')->sole()->order_number)->toBe('SA-0001')
            ->and(requestsEvents(PaymentRequested::class))->toBe(1);
        expect(json_decode((string) DB::table('public.outbox_messages')->where('type', PaymentRequested::class)->value('payload'), true))
            ->toMatchArray(['requestId' => $request->requestId, 'orderId' => REQUESTS_ORDER, 'storeId' => $store, 'customerId' => REQUESTS_BUYER]);
    });

    it('answers the same amount with the same request, and another amount is refused', function () {
        $store = requestsGateway();
        $first = requestsAsk($store);

        expect(requestsAsk($store)->requestId)->toBe($first->requestId)
            ->and(requestsEvents(PaymentRequested::class))->toBe(1)
            ->and(fn () => requestsAsk($store, 9900))->toThrow(AmountChanged::class)
            ->and(DB::table('payments.requests')->count())->toBe(1);
    });

    it('sends a new one once the last has ended, the old one marked expired', function () {
        $store = requestsGateway();
        $first = requestsAsk($store);
        requestsAt('+60 minutes');

        $second = requestsAsk($store, 9900);

        expect($second->requestId)->not->toBe($first->requestId)
            ->and(requestsState($first->requestId))->toBe('EXPIRED')
            ->and(requestsState($second->requestId))->toBe('OPEN')
            ->and(requestsEvents(PaymentRequested::class))->toBe(2);
    });

    it('is still open a moment before its 60 minutes end', function () {
        $store = requestsGateway();
        $first = requestsAsk($store);
        requestsAt('+59 minutes');

        expect(requestsAsk($store)->requestId)->toBe($first->requestId)
            ->and(app(PaymentsApi::class)->openRequest(REQUESTS_ORDER)?->requestId)->toBe($first->requestId);
    });

    it('sends none while a payment on the order is open', function () {
        ['store' => $store, 'request' => $request] = requestsCharged();
        requestsAt('+70 minutes');

        expect(fn () => requestsAsk($store))->toThrow(PaymentInProgress::class)
            ->and(requestsState($request->requestId))->toBe('OPEN');
    });

    it('needs the gateway on, with a good key', function () {
        $store = Fx::storeId('sa');

        expect(fn () => requestsAsk($store))->toThrow(NoOnlinePayment::class);

        requestsGateway();
        app(SwitchGatewayHandler::class)->handle(new SwitchGateway($store, false));
        expect(fn () => requestsAsk($store))->toThrow(NoOnlinePayment::class);

        app(SwitchGatewayHandler::class)->handle(new SwitchGateway($store, true));
        DB::table('payments.gateway_keys')->where('store_id', $store)->update(['rejected_at' => CarbonImmutable::now()]);
        expect(fn () => requestsAsk($store))->toThrow(NoOnlinePayment::class);
    });

    it('never asks real money on a test order, nor test money on a real one', function () {
        $store = requestsGateway('TEST');
        expect(fn () => requestsAsk($store, mode: PaymentMode::Live))->toThrow(NoOnlinePayment::class);
    });

    it('asks only for more than nothing, in the store\'s currency', function () {
        $store = requestsGateway();
        $dto = fn (Money $amount) => new PaymentRequestDto(REQUESTS_ORDER, 'SA-0001', StoreId::fromString($store), $amount, REQUESTS_BUYER, PaymentMode::Test);

        expect(fn () => app(PaymentsApi::class)->requestPayment($dto(Money::of(0, 'SAR')), REQUESTS_STAFF))->toThrow(InvalidPaymentsInput::class)
            ->and(fn () => app(PaymentsApi::class)->requestPayment($dto(Money::of(100, 'USD')), REQUESTS_STAFF))->toThrow(InvalidPaymentsInput::class);
    });

    it('answers from the winner when two get past the order\'s lock at the same instant', function (int $amount, bool $same) {
        $store = requestsGateway();
        $won = requestsAsk($store);
        $real = app(PaymentRequests::class);
        // This one looked before the winner's row was there.
        app()->instance(PaymentRequests::class, new class($real) implements PaymentRequests
        {
            public function __construct(private PaymentRequests $real) {}

            public function add(PaymentRequest $request): bool
            {
                return $this->real->add($request);
            }

            public function lock(string $id): ?PaymentRequest
            {
                return $this->real->lock($id);
            }

            public function openOf(string $orderId): ?PaymentRequest
            {
                return $this->real->openOf($orderId);
            }

            public function stillOpenOf(array $orderIds, DateTimeImmutable $now): array
            {
                return $this->real->stillOpenOf($orderIds, $now);
            }

            public function lockOpenOf(string $orderId): ?PaymentRequest
            {
                return null;
            }

            public function end(string $id, PaymentRequestState $state, DateTimeImmutable $now): void
            {
                $this->real->end($id, $state, $now);
            }
        });

        if ($same) {
            expect(requestsAsk($store, $amount)->requestId)->toBe($won->requestId);
        } else {
            expect(fn () => requestsAsk($store, $amount))->toThrow(AmountChanged::class);
        }

        expect(DB::table('payments.requests')->count())->toBe(1)
            ->and(requestsEvents(PaymentRequested::class))->toBe(1);
    })->with(['the same amount' => [4500, true], 'another amount' => [9900, false]]);

    it('lets two orders each have their own', function () {
        $store = requestsGateway();
        $first = requestsAsk($store);
        $second = requestsAsk($store, order: strtolower((string) Str::ulid()));

        expect($second->requestId)->not->toBe($first->requestId);
    });
});

describe('the open request', function () {
    it('is null when none, and ends by itself past its time with nothing open', function () {
        $store = requestsGateway();
        expect(app(PaymentsApi::class)->openRequest(REQUESTS_ORDER))->toBeNull();

        $request = requestsAsk($store);
        expect(app(PaymentsApi::class)->openRequest(REQUESTS_ORDER)?->requestId)->toBe($request->requestId);

        requestsAt('+60 minutes');

        expect(app(PaymentsApi::class)->openRequest(REQUESTS_ORDER))->toBeNull()
            ->and(requestsState($request->requestId))->toBe('EXPIRED');
    });

    it('never expires while a payment on it is open: its 60 minutes wait for the answer', function () {
        ['request' => $request] = requestsCharged();
        requestsAt('+61 minutes');

        expect(app(PaymentsApi::class)->openRequest(REQUESTS_ORDER)?->requestId)->toBe($request->requestId)
            ->and(requestsState($request->requestId))->toBe('OPEN');
    });

    it('reads a page of orders at once, by order, writing nothing', function () {
        $store = requestsGateway();
        $other = strtolower((string) Str::ulid());
        $first = requestsAsk($store);
        $second = requestsAsk($store, 9900, order: $other);
        $none = strtolower((string) Str::ulid());

        $found = app(PaymentsApi::class)->openRequests([REQUESTS_ORDER, $other, $none]);

        expect(array_keys($found))->toEqualCanonicalizing([REQUESTS_ORDER, $other])
            ->and($found[REQUESTS_ORDER]->requestId)->toBe($first->requestId)
            ->and($found[$other]->requestId)->toBe($second->requestId)
            ->and($found[$other]->amount->minorUnits)->toBe(9900)
            ->and(app(PaymentsApi::class)->openRequests([]))->toBe([])
            ->and(array_keys(app(PaymentsApi::class)->openRequests([$other])))->toBe([$other]);

        app(PaymentsApi::class)->withdraw($other);
        expect(array_keys(app(PaymentsApi::class)->openRequests([REQUESTS_ORDER, $other])))->toBe([REQUESTS_ORDER]);
    });

    it('leaves out of a page one past its time, and marks nothing', function () {
        $store = requestsGateway();
        $request = requestsAsk($store);
        requestsAt('+59 minutes');
        expect(app(PaymentsApi::class)->openRequests([REQUESTS_ORDER]))->toHaveKey(REQUESTS_ORDER);

        requestsAt('+60 minutes');

        expect(app(PaymentsApi::class)->openRequests([REQUESTS_ORDER]))->toBe([])
            ->and(requestsState($request->requestId))->toBe('OPEN');
    });

    it('keeps on a page one past its time while a payment on it is open', function () {
        ['request' => $request] = requestsCharged();
        requestsAt('+61 minutes');

        expect(app(PaymentsApi::class)->openRequests([REQUESTS_ORDER])[REQUESTS_ORDER]->requestId)->toBe($request->requestId);
    });

    it('says whether a payment is open on the order, a try\'s or a request\'s', function () {
        ['fake' => $fake, 'request' => $request] = requestsCharged();

        expect(app(PaymentsApi::class)->paymentOpen(REQUESTS_ORDER))->toBeTrue()
            ->and(app(PaymentsApi::class)->paymentOpen(strtolower((string) Str::ulid())))->toBeFalse();

        $fake->declined();
        app(PaymentsApi::class)->refresh($request->requestId);

        expect(app(PaymentsApi::class)->paymentOpen(REQUESTS_ORDER))->toBeFalse();
    });
});

describe('withdrawing', function () {
    it('withdraws an open one, and says yes when there is none', function () {
        $store = requestsGateway();
        expect(app(PaymentsApi::class)->withdraw(REQUESTS_ORDER))->toBeTrue();

        $request = requestsAsk($store);

        expect(app(PaymentsApi::class)->withdraw(REQUESTS_ORDER))->toBeTrue()
            ->and(requestsState($request->requestId))->toBe('WITHDRAWN')
            ->and(app(PaymentsApi::class)->openRequest(REQUESTS_ORDER))->toBeNull()
            ->and(app(PaymentsApi::class)->withdraw(REQUESTS_ORDER))->toBeTrue();
    });

    it('refuses while a payment on it is open', function () {
        ['request' => $request] = requestsCharged();

        expect(app(PaymentsApi::class)->withdraw(REQUESTS_ORDER))->toBeFalse()
            ->and(requestsState($request->requestId))->toBe('OPEN');
    });

    it('goes when the order is cancelled, a payment in progress still answered and recorded', function () {
        ['fake' => $fake, 'request' => $request] = requestsCharged();

        app(WithdrawRequestWhenCancelled::class)->handle(new OrderCancelled((string) Str::uuid(), REQUESTS_ORDER, Fx::storeId('sa'), REQUESTS_BUYER, CarbonImmutable::now()));

        expect(requestsState($request->requestId))->toBe('WITHDRAWN')
            ->and(app(PaymentsApi::class)->openRequest(REQUESTS_ORDER))->toBeNull();

        $fake->paid('45.00');
        $state = app(PaymentsApi::class)->refresh($request->requestId);

        expect($state->state)->toBe(PaymentState::Succeeded)
            ->and(requestsState($request->requestId))->toBe('WITHDRAWN')
            ->and(requestsEvents(PaymentSucceeded::class))->toBe(1);
    });

    it('leaves another order\'s request alone', function () {
        $store = requestsGateway();
        $request = requestsAsk($store);

        app(PaymentsApi::class)->withdrawForCancelled(strtolower((string) Str::ulid()));

        expect(requestsState($request->requestId))->toBe('OPEN');
    });
});

describe('paying it', function () {
    it('prepares on its session, with the request\'s own amount', function () {
        FakeMyFatoorah::install();
        $store = requestsGateway();
        $request = requestsAsk($store);

        $attempt = requestsPay($store, $request->requestId, requestsSession($store, $request->requestId));

        $row = DB::table('payments.attempts')->where('id', $attempt)->sole();
        expect($row->owner_kind)->toBe('REQUEST')
            ->and($row->owner_id)->toBe($request->requestId)
            ->and($row->order_id)->toBe(REQUESTS_ORDER)
            ->and((int) $row->amount_minor)->toBe(4500);
    });

    it('answers a double click with the same attempt, and another window with in progress', function () {
        FakeMyFatoorah::install();
        $store = requestsGateway();
        $request = requestsAsk($store);
        $session = requestsSession($store, $request->requestId);
        $attempt = requestsPay($store, $request->requestId, $session);

        expect(requestsPay($store, $request->requestId, $session))->toBe($attempt);

        DB::table('payments.sessions')->insert([...(array) DB::table('payments.sessions')->sole(), 'id' => 'KWT-another', 'used_by' => null]);

        expect(fn () => requestsPay($store, $request->requestId, 'KWT-another'))->toThrow(PaymentInProgress::class);
    });

    it('answers a double click with the same attempt even past the 60 minutes, while its payment is open', function () {
        FakeMyFatoorah::install();
        $store = requestsGateway();
        $request = requestsAsk($store);
        $session = requestsSession($store, $request->requestId);
        $attempt = requestsPay($store, $request->requestId, $session);
        requestsAt('+61 minutes');

        expect(requestsPay($store, $request->requestId, $session))->toBe($attempt)
            ->and(DB::table('payments.attempts')->count())->toBe(1);
    });

    it('refuses a request that ended, however it ended', function (Closure $end) {
        FakeMyFatoorah::install();
        $store = requestsGateway();
        $request = requestsAsk($store);
        $session = requestsSession($store, $request->requestId);
        $end($request);

        expect(fn () => requestsPay($store, $request->requestId, $session))->toThrow(RequestNotOpen::class)
            ->and(DB::table('payments.attempts')->count())->toBe(0);
    })->with([
        'withdrawn' => [function (PaymentRequestStateDto $request): void {
            app(PaymentsApi::class)->withdraw($request->orderId);
        }],
        'its time over' => [function (PaymentRequestStateDto $request): void {
            requestsAt('+60 minutes');
        }],
        'paid' => [function (PaymentRequestStateDto $request): void {
            DB::table('payments.requests')->where('id', $request->requestId)->update(['state' => 'PAID']);
        }],
    ]);

    it('refuses what is not this request\'s', function (Closure $call) {
        FakeMyFatoorah::install();
        $store = requestsGateway();
        $request = requestsAsk($store);

        expect(fn () => $call($store, $request))->toThrow(RequestNotOpen::class)
            ->and(DB::table('payments.attempts')->count())->toBe(0);
    })->with([
        'none by that id' => [function (string $store): void {
            $id = strtolower((string) Str::ulid());
            requestsPay($store, $id, requestsSession($store, $id));
        }],
        'another order' => [function (string $store, PaymentRequestStateDto $request): void {
            requestsPay($store, $request->requestId, requestsSession($store, $request->requestId), order: strtolower((string) Str::ulid()));
        }],
        'another buyer' => [function (string $store, PaymentRequestStateDto $request): void {
            $buyer = strtolower((string) Str::ulid());
            requestsPay($store, $request->requestId, requestsSession($store, $request->requestId, buyer: $buyer), buyer: $buyer);
        }],
        'another store' => [function (string $store, PaymentRequestStateDto $request): void {
            DB::table('payments.requests')->where('id', $request->requestId)->update(['store_id' => Fx::storeId('eg')]);
            requestsPay($store, $request->requestId, requestsSession($store, $request->requestId));
        }],
    ]);

    it('takes the amount from the request, never the page', function () {
        FakeMyFatoorah::install();
        $store = requestsGateway();
        $request = requestsAsk($store);
        $session = requestsSession($store, $request->requestId, 100);

        expect(fn () => requestsPay($store, $request->requestId, $session, 100))->toThrow(AmountChanged::class)
            ->and(DB::table('payments.attempts')->count())->toBe(0);
    });

    it('refuses once the gateway has changed mode: a test request never takes real money', function () {
        FakeMyFatoorah::install();
        $store = requestsGateway('TEST');
        $request = requestsAsk($store);
        app(ChangeKeysHandler::class)->handle(new ChangeKeys($store, 'LIVE', 'sk_key_ABC124', 'whsec_secret_2', '2030-12-31'));
        app(SwitchModeHandler::class)->handle(new SwitchMode($store, 'LIVE'));

        expect(fn () => requestsPay($store, $request->requestId, requestsSession($store, $request->requestId)))->toThrow(RequestNotOpen::class);
    });
});

describe('its answer', function () {
    it('closes as paid on a success, once, with the money for Sales to record', function () {
        ['fake' => $fake, 'request' => $request] = requestsCharged();
        $fake->paid('45.00');

        $state = app(PaymentsApi::class)->refresh($request->requestId);

        expect($state->state)->toBe(PaymentState::Succeeded)
            ->and(requestsState($request->requestId))->toBe('PAID')
            ->and(requestsEvents(PaymentSucceeded::class))->toBe(1)
            ->and(app(PaymentsApi::class)->openRequest(REQUESTS_ORDER))->toBeNull()
            ->and(app(PaymentsApi::class)->payment((string) $state->paymentId)?->ownerKind)->toBe(PaymentOwnerKind::Request)
            ->and(app(PaymentsApi::class)->payment((string) $state->paymentId)?->orderId)->toBe(REQUESTS_ORDER);
    });

    it('closes as not accepted on a currency refused, needing attention, nothing for the order', function () {
        ['fake' => $fake, 'request' => $request, 'attempt' => $attempt] = requestsCharged();
        $fake->paid('45.00');
        $fake->displayCurrency = 'USD';

        $state = app(PaymentsApi::class)->refresh($request->requestId);

        expect($state->state)->toBe(PaymentState::NotAccepted)
            ->and(requestsState($request->requestId))->toBe('NOT_ACCEPTED')
            ->and(requestsEvents(PaymentSucceeded::class))->toBe(0)
            ->and(requestsEvents(PaymentNotAccepted::class))->toBe(1)
            ->and(DB::table('payments.attempts')->where('id', $attempt)->value('needs_attention'))->toBeTrue();
    });

    it('stays open after a decline while its time lasts, for another try', function () {
        ['fake' => $fake, 'store' => $store, 'request' => $request] = requestsCharged();
        $fake->declined();

        expect(app(PaymentsApi::class)->refresh($request->requestId)->state)->toBe(PaymentState::Failed)
            ->and(requestsState($request->requestId))->toBe('OPEN');

        $fake->invoiceId = '7320291';
        $second = requestsPay($store, $request->requestId, requestsSession($store, $request->requestId));

        expect($second)->not->toBeEmpty()
            ->and(DB::table('payments.attempts')->count())->toBe(2);
    });

    it('stays open when the hold limit passes with no word, and may be withdrawn then', function () {
        ['fake' => $fake, 'request' => $request] = requestsCharged();
        $fake->statusAnswer = fn () => throw new ConnectionException('away');
        requestsAt('+16 minutes');

        expect(app(PaymentsApi::class)->refresh($request->requestId)->state)->toBe(PaymentState::Expired)
            ->and(requestsState($request->requestId))->toBe('OPEN')
            ->and(app(PaymentsApi::class)->withdraw(REQUESTS_ORDER))->toBeTrue();
    });

    it('still records a success on a request that has ended, the request left as it was', function () {
        ['fake' => $fake, 'request' => $request] = requestsCharged();
        $fake->statusAnswer = fn () => throw new ConnectionException('away');
        requestsAt('+16 minutes');
        app(PaymentsApi::class)->refresh($request->requestId);
        app(PaymentsApi::class)->withdraw(REQUESTS_ORDER);
        $fake->statusAnswer = null;
        $fake->paid('45.00');
        requestsAt('+20 minutes');

        app(PaymentsApi::class)->refresh($request->requestId);
        app(AttemptChecks::class)->due((string) DB::table('payments.attempts')->value('id'));

        expect(requestsState($request->requestId))->toBe('WITHDRAWN')
            ->and(requestsEvents(PaymentSucceeded::class))->toBe(1);
    });

    it('lists the attempts on an order, oldest first, with what was paid by', function () {
        ['fake' => $fake, 'store' => $store, 'request' => $request, 'attempt' => $first] = requestsCharged();
        $fake->declined();
        app(PaymentsApi::class)->refresh($request->requestId);
        $fake->invoiceId = '7320291';
        $second = requestsPay($store, $request->requestId, requestsSession($store, $request->requestId));

        $attempts = app(PaymentsApi::class)->attempts(REQUESTS_ORDER);

        expect(array_map(fn ($a) => $a->attemptId, $attempts))->toBe([$first, $second])
            ->and($attempts[0]->state)->toBe('FAILED')
            ->and($attempts[0]->method)->toBeNull()
            ->and($attempts[0]->ownerKind)->toBe(PaymentOwnerKind::Request)
            ->and($attempts[0]->amount->minorUnits)->toBe(4500)
            ->and($attempts[0]->mode)->toBe(PaymentMode::Test)
            ->and(app(PaymentsApi::class)->attempts(strtolower((string) Str::ulid())))->toBe([]);
    });

    it('names the method of a paid attempt', function () {
        ['fake' => $fake, 'request' => $request] = requestsCharged();
        $fake->paid('45.00');
        app(PaymentsApi::class)->refresh($request->requestId);

        expect(app(PaymentsApi::class)->attempts(REQUESTS_ORDER)[0]->method)->toBe('Card');
    });
});

describe('the table', function () {
    it('keeps one open request per order, whatever gets past the locks', function () {
        $store = requestsGateway();
        $request = requestsAsk($store);
        $copy = [...(array) DB::table('payments.requests')->sole(), 'id' => strtolower((string) Str::ulid())];

        expect(fn () => DB::transaction(fn () => DB::table('payments.requests')->insert($copy)))->toThrow(UniqueConstraintViolationException::class);

        app(PaymentsApi::class)->withdraw(REQUESTS_ORDER);
        DB::table('payments.requests')->insert($copy);

        expect(DB::table('payments.requests')->count())->toBe(2)
            ->and(requestsState($request->requestId))->toBe('WITHDRAWN');
    });
});

describe('the log', function () {
    it('removes notices past 90 days and calls past a year, and keeps the rest', function () {
        $store = requestsGateway();
        $now = CarbonImmutable::now();
        $notice = fn (string $reference, CarbonImmutable $at) => DB::table('payments.notices')->insert(['store_id' => $store, 'gateway' => 'MYFATOORAH', 'mode' => 'TEST', 'event_reference' => $reference, 'received_at' => $at, 'payload' => '{}']);
        $call = fn (string $endpoint, CarbonImmutable $at) => DB::table('payments.calls')->insert(['store_id' => $store, 'endpoint' => $endpoint, 'took_ms' => 1, 'at' => $at]);
        $notice('old', $now->subDays(90)->subSecond());
        $notice('kept', $now->subDays(90));
        $call('/old', $now->subYear()->subSecond());
        $call('/kept', $now->subYear());

        app(PaymentLogPruning::class)->prune();

        expect(DB::table('payments.notices')->pluck('event_reference')->all())->toBe(['kept'])
            ->and(DB::table('payments.calls')->pluck('endpoint')->all())->toBe(['/kept']);
    });

    it('runs each day', function () {
        $events = array_filter(Schedule::events(), fn ($event) => str_contains((string) $event->description, class_basename(PrunePaymentLogJob::class)) || str_contains((string) $event->description, PrunePaymentLogJob::class));

        expect($events)->toHaveCount(1)
            ->and(array_values($events)[0]->expression)->toBe('45 3 * * *');
    });
});
