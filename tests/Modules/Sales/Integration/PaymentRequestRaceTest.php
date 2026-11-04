<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Public\Dto\PaymentRequestStateDto;
use Modules\Sales\Application\Command\OpenRequestPayment\OpenRequestPayment;
use Modules\Sales\Application\Command\OpenRequestPayment\OpenRequestPaymentHandler;
use Modules\Sales\Application\Command\PayOrderRequest\PayOrderRequest;
use Modules\Sales\Application\Command\PayOrderRequest\PayOrderRequestHandler;
use Modules\Sales\Application\Command\RecordPayment\RecordPayment;
use Modules\Sales\Application\Command\RecordPayment\RecordPaymentHandler;
use Modules\Sales\Application\Command\RequestOrderPayment\RequestOrderPayment;
use Modules\Sales\Application\Command\RequestOrderPayment\RequestOrderPaymentHandler;
use Modules\Sales\Application\Command\WithdrawOrderRequest\WithdrawOrderRequest;
use Modules\Sales\Application\Command\WithdrawOrderRequest\WithdrawOrderRequestHandler;
use Modules\Sales\Domain\Exception\PaymentBeingConfirmed;
use Modules\Sales\Domain\Exception\PaymentLinkEnded;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Payments\Support\FakeMyFatoorah;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| One lock settles every race on a request (payments.md §1.5): Sales locks the order first, then Payments the request.
| Another connection holds a row, and the call under test must wait on exactly that lock: so a Pay, a hand recording
| and a withdrawal can never pass each other, and the same balance is never paid twice.
|
| Outside RefreshDatabase's transaction (lesson 87): it commits its own rows and empties the database before and
| after itself; skipped under --parallel.
*/

function orderPayRaceInParallel(): bool
{
    return ParallelTesting::token() !== false;
}

beforeEach(function () {
    if (orderPayRaceInParallel()) {
        return;
    }

    Artisan::call('migrate:fresh');
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    config(['database.connections.order_pay_race' => config('database.connections.'.config('database.default'))]);
    app()->instance(KeyTester::class, new class implements KeyTester
    {
        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void {}
    });
    Sleep::fake();
    CarbonImmutable::setTestNow('2027-01-01T12:00:00Z');
    Carbon::setTestNow('2027-01-01T12:00:00Z');
    RateLimiter::clear('payments-checks');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();

    if (orderPayRaceInParallel()) {
        return;
    }

    DB::connection('order_pay_race')->disconnect();
    DB::statement('SET lock_timeout = 0');
    Cache::flush();
    app()->forgetScopedInstances();
    Artisan::call('migrate:fresh');
});

/**
 * @return array{fake: FakeMyFatoorah, order: string, number: string, request: PaymentRequestStateDto, session: string}
 */
function orderPayRaceReady(): array
{
    $fake = FakeMyFatoorah::install();
    Cx::asSuperAdmin(function (): void {
        app(ChangeKeysHandler::class)->handle(new ChangeKeys(Fx::storeId('sa'), 'LIVE', 'sk_key_ABC123', 'whsec_secret_1', '2030-12-31'));
        app(SwitchGatewayHandler::class)->handle(new SwitchGateway(Fx::storeId('sa'), true));
    });
    ['address' => $address] = Cx::ready();
    $placed = Cx::place(Cx::start($address)->id);
    $request = Cx::asSuperAdmin(static fn (): PaymentRequestStateDto => app(RequestOrderPaymentHandler::class)->handle(new RequestOrderPayment($placed->id)));
    $session = app(OpenRequestPaymentHandler::class)->handle(new OpenRequestPayment(Fx::storeId('sa'), $placed->number, 'en'))->sessionId;

    return ['fake' => $fake, 'order' => $placed->id, 'number' => $placed->number, 'request' => $request, 'session' => $session];
}

function orderPayRacePay(string $number, string $session): mixed
{
    return app(PayOrderRequestHandler::class)->handle(new PayOrderRequest(Fx::storeId('sa'), $number, $session, 'en', 'https://shop.example.test/return'));
}

function orderPayRaceByHand(string $order): mixed
{
    return Cx::asSuperAdmin(static fn () => app(RecordPaymentHandler::class)->handle(new RecordPayment($order, 'OTHER', null, 'Cash', '2027-01-01', (string) Str::uuid())));
}

/**
 * Runs `$work` while another connection holds a row, and answers what it waited on.
 *
 * @param  Closure(): mixed  $work
 */
function orderPayRaceWaitsOn(string $table, string $id, Closure $work): QueryException
{
    $other = DB::connection('order_pay_race');
    $other->beginTransaction();
    $other->table($table)->where('id', $id)->lockForUpdate()->first();
    DB::statement("SET lock_timeout = '500ms'");

    try {
        $work();
    } catch (QueryException $timedOut) {
        return $timedOut;
    } finally {
        $other->rollBack();
        DB::statement('SET lock_timeout = 0');
    }

    throw new LogicException("It did not wait on {$table}.");
}

function orderPayRaceExpectWait(QueryException $waited, string $table): void
{
    expect($waited->getCode())->toBe('55P03')
        ->and(strtolower($waited->getSql()))->toContain($table)
        ->and(strtolower($waited->getSql()))->toContain('for update');
}

describe('at the same moment', function () {
    it('makes Pay wait on the order first, so a hand recording and a Pay can never pass each other', function () {
        ['order' => $order, 'number' => $number, 'session' => $session, 'fake' => $fake] = orderPayRaceReady();

        orderPayRaceExpectWait(orderPayRaceWaitsOn('sales.orders', $order, fn () => orderPayRacePay($number, $session)), '"sales"."orders"');

        expect(DB::table('payments.attempts')->count())->toBe(0)
            ->and($fake->count('POST', '/v3/payments'))->toBe(0);
    })->skip(fn (): bool => orderPayRaceInParallel(), 'Empties its database: run serially.');

    it('makes a hand recording wait on the order first, and then on the request', function () {
        ['order' => $order, 'request' => $request] = orderPayRaceReady();

        orderPayRaceExpectWait(orderPayRaceWaitsOn('sales.orders', $order, fn () => orderPayRaceByHand($order)), '"sales"."orders"');
        orderPayRaceExpectWait(orderPayRaceWaitsOn('payments.requests', $request->requestId, fn () => orderPayRaceByHand($order)), '"payments"."requests"');

        expect(DB::table('sales.payments')->count())->toBe(0);
    })->skip(fn (): bool => orderPayRaceInParallel(), 'Empties its database: run serially.');

    it('makes Pay wait on the request after the order', function () {
        ['number' => $number, 'request' => $request, 'session' => $session] = orderPayRaceReady();

        orderPayRaceExpectWait(orderPayRaceWaitsOn('payments.requests', $request->requestId, fn () => orderPayRacePay($number, $session)), '"payments"."requests"');

        expect(DB::table('payments.attempts')->count())->toBe(0);
    })->skip(fn (): bool => orderPayRaceInParallel(), 'Empties its database: run serially.');

    it('makes staff\'s withdrawal wait on the order first', function () {
        ['order' => $order] = orderPayRaceReady();

        orderPayRaceExpectWait(orderPayRaceWaitsOn('sales.orders', $order, fn () => Cx::asSuperAdmin(static fn () => app(WithdrawOrderRequestHandler::class)->handle(new WithdrawOrderRequest($order)))), '"sales"."orders"');
    })->skip(fn (): bool => orderPayRaceInParallel(), 'Empties its database: run serially.');

    it('settles a Pay then a hand recording with one money record: the second waits for the answer', function () {
        ['order' => $order, 'number' => $number, 'session' => $session, 'fake' => $fake] = orderPayRaceReady();

        orderPayRacePay($number, $session);

        expect(fn () => orderPayRaceByHand($order))->toThrow(PaymentBeingConfirmed::class)
            ->and(DB::table('sales.payments')->count())->toBe(0)
            ->and($fake->count('POST', '/v3/payments'))->toBe(1);
    })->skip(fn (): bool => orderPayRaceInParallel(), 'Empties its database: run serially.');

    it('settles a hand recording then a Pay with no charge: the request was withdrawn', function () {
        ['order' => $order, 'number' => $number, 'session' => $session, 'fake' => $fake] = orderPayRaceReady();

        orderPayRaceByHand($order);

        expect(fn () => orderPayRacePay($number, $session))->toThrow(PaymentLinkEnded::class)
            ->and($fake->count('POST', '/v3/payments'))->toBe(0)
            ->and(DB::table('sales.payments')->count())->toBe(1);
    })->skip(fn (): bool => orderPayRaceInParallel(), 'Empties its database: run serially.');
});
