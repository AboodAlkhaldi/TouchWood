<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\CheckAttempt\CheckAttempt;
use Modules\Payments\Application\Command\CheckAttempt\CheckAttemptHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Application\Query\ListPayments\ListPayments;
use Modules\Payments\Application\Query\ListPayments\ListPaymentsHandler;
use Modules\Payments\Application\Query\ListPayments\PaymentRow;
use Modules\Payments\Application\Query\ListPayments\PaymentsPage;
use Modules\Payments\Application\Query\ViewAttempt\ViewAttempt;
use Modules\Payments\Application\Query\ViewAttempt\ViewAttemptHandler;
use Modules\Payments\Domain\Exception\AttemptNotFound;
use Modules\Payments\Domain\Exception\InvalidPaymentsInput;
use Modules\Payments\Public\Contracts\PaymentOrderNumbers;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\PayRequestDto;
use Modules\Payments\Public\Dto\SessionRequestDto;
use Modules\Payments\Public\Enums\PaymentOwnerKind;
use Modules\Payments\Public\Enums\PaymentState;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Payments\Support\FakeMyFatoorah;

use function Pest\Laravel\seed;

/*
| What staff read of online payments (payments.md §3, §4.2): the list, one attempt, and "Ask the gateway now".
*/

uses(RefreshDatabase::class);

const READS_NOW = '2027-01-01T12:00:00Z';

beforeEach(function () {
    seed(PlatformSeeder::class);
    app()->instance(KeyTester::class, new class implements KeyTester
    {
        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void {}
    });
    Sleep::fake();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    CarbonImmutable::setTestNow(READS_NOW);
    Carbon::setTestNow(READS_NOW);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

function readsGateway(string $code = 'sa', string $mode = 'TEST'): string
{
    $store = Fx::storeId($code);
    app(ChangeKeysHandler::class)->handle(new ChangeKeys($store, $mode, 'sk_key_ABC123', 'whsec_secret_1', '2030-12-31'));
    app(SwitchGatewayHandler::class)->handle(new SwitchGateway($store, true));

    return $store;
}

/** A charged attempt for a try of its own: the fake gives each its own invoice. */
function readsAttempt(FakeMyFatoorah $fake, string $store, int $number = 1): string
{
    $fake->invoiceId = (string) (7320290 + $number);
    $owner = strtolower((string) Str::ulid());
    $session = app(PaymentsApi::class)->openSession(new SessionRequestDto(StoreId::fromString($store), "quote-{$owner}", 'buyer-1', Money::of(4500, 'SAR'), 'en', CarbonImmutable::now()->addMinutes(30)))->sessionId;
    $attempt = app(PaymentsApi::class)->prepare(new PayRequestDto(
        $owner, PaymentOwnerKind::Try, strtolower((string) Str::ulid()), "quote-{$owner}", StoreId::fromString($store), 'buyer-1', $session, Money::of(4500, 'SAR'),
        'A Buyer', 'buyer@example.test', '+966500000000', 'en', 'https://shop.example.test/return',
    ));
    app(PaymentsApi::class)->charge($attempt);

    return $attempt;
}

function readsList(?string $store = null, ?string $state = null, ?string $mode = null, ?string $after = null): PaymentsPage
{
    return app(ListPaymentsHandler::class)->handle(new ListPayments($store, $state, $mode, $after));
}

/** @param list<PaymentRow> $rows
 * @return list<string> */
function readsIds(array $rows): array
{
    return array_map(static fn (PaymentRow $row): string => $row->attemptId, $rows);
}

/**
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function readsAsStaffWith(array $permissions, array $stores = ['sa']): void
{
    Fx::actAsStaff(tap(Fx::staff(), fn (string $staff) => Fx::personalRole($staff, $permissions, $stores)));
}

describe('the list', function () {
    it('shows each attempt, newest first, with what the screen says of it', function () {
        $fake = FakeMyFatoorah::install();
        $store = readsGateway();
        $first = readsAttempt($fake, $store, 1);
        usleep(2000);
        $second = readsAttempt($fake, $store, 2);

        $page = readsList($store);

        expect(readsIds($page->rows))->toBe([$second, $first])
            ->and($page->nextCursor)->toBeNull()
            ->and($page->needingAttention)->toBe([])
            ->and($page->needingAttentionCount)->toBe(0)
            ->and($page->rows[0]->amountMinor)->toBe(4500)
            ->and($page->rows[0]->currencyCode)->toBe('SAR')
            ->and($page->rows[0]->customerName)->toBe('A Buyer')
            ->and($page->rows[0]->state)->toBe('OPEN')
            ->and($page->rows[0]->isTest())->toBeTrue()
            ->and($page->rows[0]->orderNumber)->toBeNull()
            ->and($page->rows[0]->reasons)->toBe([]);
    });

    it('goes 50 at a time, and the next page takes up where it stopped', function () {
        $fake = FakeMyFatoorah::install();
        $store = readsGateway();
        // One charged; the others its copies, as sessions are limited to 30 an hour.
        $ids = [readsAttempt($fake, $store)];

        foreach (range(2, 51) as $number) {
            usleep(1500);
            $ids[] = $id = strtolower((string) Str::ulid());
            DB::table('payments.attempts')->insert([...(array) DB::table('payments.attempts')->where('id', $ids[0])->sole(), 'id' => $id, 'owner_id' => "owner-{$number}", 'invoice_id' => null, 'idempotency_key' => (string) Str::uuid()]);
        }

        $first = readsList($store);
        $second = readsList($store, after: $first->nextCursor);

        expect($first->rows)->toHaveCount(50)
            ->and($first->nextCursor)->toBe($ids[1])
            ->and($second->rows)->toHaveCount(1)
            ->and(readsIds($second->rows))->toBe([$ids[0]])
            ->and($second->nextCursor)->toBeNull();
    });

    it('filters by state and mode', function () {
        $fake = FakeMyFatoorah::install();
        $store = readsGateway();
        $open = readsAttempt($fake, $store, 1);
        DB::table('payments.attempts')->insert([...(array) DB::table('payments.attempts')->where('id', $open)->sole(), 'id' => $failed = strtolower((string) Str::ulid()), 'owner_id' => 'other', 'state' => 'FAILED', 'invoice_id' => null, 'idempotency_key' => (string) Str::uuid(), 'mode' => 'LIVE']);

        expect(readsIds(readsList($store, state: 'OPEN')->rows))->toBe([$open])
            ->and(readsIds(readsList($store, state: 'FAILED')->rows))->toBe([$failed])
            ->and(readsIds(readsList($store, mode: 'LIVE')->rows))->toBe([$failed])
            ->and(readsIds(readsList($store, mode: 'TEST')->rows))->toBe([$open]);
    });

    it('refuses filters that mean nothing', function () {
        expect(fn () => readsList(state: 'NOPE'))->toThrow(InvalidPaymentsInput::class)
            ->and(fn () => readsList(mode: 'NOPE'))->toThrow(InvalidPaymentsInput::class)
            ->and(fn () => readsList(after: 'not-a-place'))->toThrow(InvalidPaymentsInput::class)
            ->and(fn () => readsList(store: 'not-a-store'))->toThrow(InvalidPaymentsInput::class);
    });

    it('puts those needing attention in their own block, on the first page only, each saying why', function () {
        $fake = FakeMyFatoorah::install();
        $store = readsGateway();
        $plain = readsAttempt($fake, $store, 1);
        $reasons = [
            'currency' => [['state' => 'SUCCEEDED', 'charge_claimed_at' => CarbonImmutable::now(), 'currency_refused' => true, 'mismatch' => true], [PaymentRow::CURRENCY_REFUSED]],
            'mismatch' => [['state' => 'SUCCEEDED', 'charge_claimed_at' => CarbonImmutable::now(), 'mismatch' => true], [PaymentRow::MISMATCH]],
            'twice' => [['state' => 'SUCCEEDED', 'charge_claimed_at' => CarbonImmutable::now(), 'paid_twice' => true], [PaymentRow::PAID_TWICE]],
            'both' => [['state' => 'SUCCEEDED', 'charge_claimed_at' => CarbonImmutable::now(), 'mismatch' => true, 'paid_twice' => true], [PaymentRow::MISMATCH, PaymentRow::PAID_TWICE]],
            'no order' => [['state' => 'SUCCEEDED', 'charge_claimed_at' => CarbonImmutable::now()], [PaymentRow::PAID_NO_ORDER]],
            'no answer' => [['state' => 'UNKNOWN', 'charge_claimed_at' => CarbonImmutable::now()], [PaymentRow::NO_ANSWER]],
        ];
        $expected = [];

        foreach ($reasons as $name => [$columns, $why]) {
            $id = strtolower((string) Str::ulid());
            DB::table('payments.attempts')->insert([...(array) DB::table('payments.attempts')->where('id', $plain)->sole(), 'id' => $id, 'owner_id' => $name, 'invoice_id' => null, 'idempotency_key' => (string) Str::uuid(), 'needs_attention' => true, ...$columns]);
            $expected[$id] = $why;
            usleep(1500);
        }

        $page = readsList($store);
        $found = [];

        foreach ($page->needingAttention as $row) {
            $found[$row->attemptId] = $row->reasons;
        }

        expect($found)->toEqual($expected)
            ->and($page->needingAttentionCount)->toBe(count($reasons))
            ->and(readsIds($page->rows))->toBe([$plain])
            ->and(readsList($store, after: $plain)->needingAttention)->toBe([])
            ->and(readsList($store, after: $plain)->needingAttentionCount)->toBe(0);
    });

    it('names the order behind an attempt, and a late success on one', function () {
        $fake = FakeMyFatoorah::install();
        $store = readsGateway();
        $attempt = readsAttempt($fake, $store);
        $orderId = (string) DB::table('payments.attempts')->where('id', $attempt)->value('order_id');
        DB::table('payments.attempts')->where('id', $attempt)->update(['state' => 'SUCCEEDED', 'needs_attention' => true]);
        app()->instance(PaymentOrderNumbers::class, new class($orderId) implements PaymentOrderNumbers
        {
            public function __construct(private string $orderId) {}

            public function numbers(array $orderIds): array
            {
                return [$this->orderId => 'SA-0042'];
            }
        });

        $row = readsList($store)->needingAttention[0];

        expect($row->orderNumber)->toBe('SA-0042')
            ->and($row->reasons)->toBe([PaymentRow::LATE_SUCCESS]);
    });

    it('shows only the stores the staff member holds', function () {
        $fake = FakeMyFatoorah::install();
        $sa = readsGateway('sa');
        $mine = readsAttempt($fake, $sa, 1);
        $other = strtolower((string) Str::ulid());
        $eg = Fx::storeId('eg');
        DB::table('payments.attempts')->insert([...(array) DB::table('payments.attempts')->where('id', $mine)->sole(), 'id' => $other, 'store_id' => $eg, 'owner_id' => 'other', 'invoice_id' => null, 'idempotency_key' => (string) Str::uuid()]);

        readsAsStaffWith([PaymentsPermissions::VIEW], ['sa']);

        expect(readsIds(readsList()->rows))->toBe([$mine])
            ->and(fn () => readsList($eg))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect(readsIds(readsList()->rows))->toBe([$other, $mine]);

        readsAsStaffWith(['sales.order.edit']);
        expect(fn () => readsList())->toThrow(Unauthorized::class);
    });
});

describe('an attempt', function () {
    it('shows its transactions and calls, with the customer for staff who may read them', function () {
        $fake = FakeMyFatoorah::install();
        $store = readsGateway();
        $attempt = readsAttempt($fake, $store);
        $fake->paid('45.00');
        $owner = (string) DB::table('payments.attempts')->where('id', $attempt)->value('owner_id');
        app(PaymentsApi::class)->refresh($owner);

        $view = app(ViewAttemptHandler::class)->handle(new ViewAttempt($attempt));

        expect($view->attempt->attemptId)->toBe($attempt)
            ->and($view->attempt->state)->toBe('SUCCEEDED')
            ->and($view->attempt->method)->toBe('Card')
            ->and($view->canAsk)->toBeFalse()
            ->and($view->contact)->toBe(['name' => 'A Buyer', 'email' => 'buyer@example.test', 'phone' => '+966500000000'])
            ->and($view->transactions)->toHaveCount(1)
            ->and($view->transactions[0]->succeeded)->toBeTrue()
            ->and($view->transactions[0]->amountMinor)->toBe(4500)
            ->and($view->transactions[0]->gatewayPaymentId)->toBe(FakeMyFatoorah::PAYMENT_ID)
            ->and($view->calls)->not->toBe([])
            ->and($view->calls[0]->endpoint)->not->toBe('')
            ->and($view->calls[0]->httpStatus)->not->toBeNull();
    });

    it('offers to ask while it is open', function () {
        $fake = FakeMyFatoorah::install();
        $attempt = readsAttempt($fake, readsGateway());

        expect(app(ViewAttemptHandler::class)->handle(new ViewAttempt($attempt))->canAsk)->toBeTrue();
    });

    it('hides the customer from staff who may not read customers', function () {
        $fake = FakeMyFatoorah::install();
        $attempt = readsAttempt($fake, readsGateway());

        readsAsStaffWith([PaymentsPermissions::VIEW]);
        expect(app(ViewAttemptHandler::class)->handle(new ViewAttempt($attempt))->contact)->toBeNull();

        readsAsStaffWith([PaymentsPermissions::VIEW, 'access.customer.view']);
        expect(app(ViewAttemptHandler::class)->handle(new ViewAttempt($attempt))->contact)->not->toBeNull();

        readsAsStaffWith([PaymentsPermissions::VIEW, 'access.customer.view'], ['eg']);
        expect(fn () => app(ViewAttemptHandler::class)->handle(new ViewAttempt($attempt)))->toThrow(Unauthorized::class);
    });

    it('hides the customer whose access is for another store', function () {
        $fake = FakeMyFatoorah::install();
        $attempt = readsAttempt($fake, readsGateway());
        $staff = Fx::staff();
        $role = Fx::role([PaymentsPermissions::VIEW, 'access.customer.view']);
        Fx::assign($staff, $role, ['sa', 'eg'], ['access.customer.view' => ['eg']]);
        Fx::actAsStaff($staff);

        expect(app(ViewAttemptHandler::class)->handle(new ViewAttempt($attempt))->contact)->toBeNull();
    });

    it('answers not found for what is not there', function () {
        expect(fn () => app(ViewAttemptHandler::class)->handle(new ViewAttempt(strtolower((string) Str::ulid()))))->toThrow(AttemptNotFound::class)
            ->and(fn () => app(ViewAttemptHandler::class)->handle(new ViewAttempt('nope')))->toThrow(AttemptNotFound::class);
    });
});

describe('asking the gateway now', function () {
    it('asks about one still open, and writes the answer as any other', function () {
        $fake = FakeMyFatoorah::install();
        $attempt = readsAttempt($fake, readsGateway());
        $fake->paid('45.00');
        CarbonImmutable::setTestNow(CarbonImmutable::parse(READS_NOW)->addSeconds(11));

        $state = app(CheckAttemptHandler::class)->handle(new CheckAttempt($attempt));

        expect($state->state)->toBe(PaymentState::Succeeded)
            ->and(DB::table('payments.attempts')->where('id', $attempt)->value('state'))->toBe('SUCCEEDED');
    });

    it('makes no call for one already over, nor more than each 10 seconds', function () {
        $fake = FakeMyFatoorah::install();
        $attempt = readsAttempt($fake, readsGateway());
        $calls = fn (): int => count($fake->calls);
        app(CheckAttemptHandler::class)->handle(new CheckAttempt($attempt));
        $asked = $calls();

        app(CheckAttemptHandler::class)->handle(new CheckAttempt($attempt));
        expect($calls())->toBe($asked);

        DB::table('payments.attempts')->where('id', $attempt)->update(['state' => 'FAILED', 'last_checked_at' => null]);
        app(CheckAttemptHandler::class)->handle(new CheckAttempt($attempt));
        expect($calls())->toBe($asked);
    });

    it('needs the permission in that store, and an attempt that is there', function () {
        $fake = FakeMyFatoorah::install();
        $attempt = readsAttempt($fake, readsGateway());

        readsAsStaffWith([PaymentsPermissions::VIEW], ['eg']);
        expect(fn () => app(CheckAttemptHandler::class)->handle(new CheckAttempt($attempt)))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect(fn () => app(CheckAttemptHandler::class)->handle(new CheckAttempt('nope')))->toThrow(AttemptNotFound::class)
            ->and(fn () => app(CheckAttemptHandler::class)->handle(new CheckAttempt(strtolower((string) Str::ulid()))))->toThrow(AttemptNotFound::class);
    });
});
