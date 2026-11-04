<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Sleep;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\PaymentRequestDto;
use Modules\Payments\Public\Dto\PaymentRequestStateDto;
use Modules\Payments\Public\Dto\PayRequestDto;
use Modules\Payments\Public\Dto\SessionRequestDto;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Payments\Public\Enums\PaymentOwnerKind;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Payments\Support\FakeMyFatoorah;

use function Pest\Laravel\seed;

/*
| One lock settles every race on a request (payments.md §1.5): another connection holds its row, and each call under
| test must wait on exactly that lock, so paying and withdrawing can never pass each other.
|
| Outside RefreshDatabase's transaction (lesson 87): it commits its own rows and empties the database before and
| after itself; skipped under --parallel.
*/

const REQUEST_RACE_ORDER = '01arz3ndektsv4rrffq69g5fav';

const REQUEST_RACE_BUYER = '01arz3ndektsv4rrffq69g5faw';

function requestRaceInParallel(): bool
{
    return ParallelTesting::token() !== false;
}

beforeEach(function () {
    if (requestRaceInParallel()) {
        return;
    }

    Artisan::call('migrate:fresh');
    seed(PlatformSeeder::class);
    config(['database.connections.request_race' => config('database.connections.'.config('database.default'))]);
    app()->instance(KeyTester::class, new class implements KeyTester
    {
        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void {}
    });
    Sleep::fake();
    CarbonImmutable::setTestNow('2027-01-01T12:00:00Z');
    Carbon::setTestNow('2027-01-01T12:00:00Z');
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();

    if (requestRaceInParallel()) {
        return;
    }

    DB::connection('request_race')->disconnect();
    DB::statement('SET lock_timeout = 0');
    Cache::flush();
    app()->forgetScopedInstances();
    Artisan::call('migrate:fresh');
});

/**
 * @return array{store: string, request: PaymentRequestStateDto}
 */
function requestRaceReady(): array
{
    FakeMyFatoorah::install();
    $store = Fx::storeId('sa');
    app(ChangeKeysHandler::class)->handle(new ChangeKeys($store, 'TEST', 'sk_key_ABC123', 'whsec_secret_1', '2030-12-31'));
    app(SwitchGatewayHandler::class)->handle(new SwitchGateway($store, true));

    return ['store' => $store, 'request' => requestRaceAsk($store)];
}

function requestRaceAsk(string $store): PaymentRequestStateDto
{
    return app(PaymentsApi::class)->requestPayment(new PaymentRequestDto(REQUEST_RACE_ORDER, 'SA-0001', StoreId::fromString($store), Money::of(4500, 'SAR'), REQUEST_RACE_BUYER, PaymentMode::Test), REQUEST_RACE_BUYER);
}

function requestRaceSession(string $store, string $requestId): string
{
    return app(PaymentsApi::class)->openSession(new SessionRequestDto(StoreId::fromString($store), $requestId, REQUEST_RACE_BUYER, Money::of(4500, 'SAR'), 'en', CarbonImmutable::now()->addMinutes(30)))->sessionId;
}

function requestRacePrepare(string $store, string $requestId, string $session): string
{
    return app(PaymentsApi::class)->prepare(new PayRequestDto(
        $requestId, PaymentOwnerKind::Request, REQUEST_RACE_ORDER, null, StoreId::fromString($store), REQUEST_RACE_BUYER, $session, Money::of(4500, 'SAR'),
        'A Buyer', 'buyer@example.test', '+966500000000', 'en', 'https://shop.example.test/return',
    ));
}

/**
 * @param  Closure(): mixed  $work
 */
function requestRaceExpectWait(string $id, Closure $work): void
{
    $other = DB::connection('request_race');
    $other->beginTransaction();
    $other->table('payments.requests')->where('id', $id)->lockForUpdate()->first();
    DB::statement("SET lock_timeout = '500ms'");
    $waited = null;

    try {
        $work();
    } catch (QueryException $timedOut) {
        $waited = $timedOut;
    } finally {
        $other->rollBack();
        DB::statement('SET lock_timeout = 0');
    }

    expect($waited)->not->toBeNull()
        ->and($waited?->getCode())->toBe('55P03')
        ->and(strtolower((string) $waited?->getSql()))->toContain('payments')
        ->and(strtolower((string) $waited?->getSql()))->toContain('requests')
        ->and(strtolower((string) $waited?->getSql()))->toContain('for update');
}

describe('at the same moment', function () {
    it('makes a withdrawal wait on the request', function () {
        ['request' => $request] = requestRaceReady();

        requestRaceExpectWait($request->requestId, fn () => app(PaymentsApi::class)->withdraw(REQUEST_RACE_ORDER));

        expect(app(PaymentsApi::class)->withdraw(REQUEST_RACE_ORDER))->toBeTrue();
    })->skip(fn (): bool => requestRaceInParallel(), 'Empties its database: run serially.');

    it('makes a cancel\'s withdrawal wait on the request', function () {
        ['request' => $request] = requestRaceReady();

        requestRaceExpectWait($request->requestId, fn () => app(PaymentsApi::class)->withdrawForCancelled(REQUEST_RACE_ORDER));
    })->skip(fn (): bool => requestRaceInParallel(), 'Empties its database: run serially.');

    it('makes a second request wait on the open one', function () {
        ['store' => $store, 'request' => $request] = requestRaceReady();

        requestRaceExpectWait($request->requestId, fn () => requestRaceAsk($store));
    })->skip(fn (): bool => requestRaceInParallel(), 'Empties its database: run serially.');

    it('makes paying wait on the request, so a withdrawal and a payment can never pass each other', function () {
        ['store' => $store, 'request' => $request] = requestRaceReady();
        $session = requestRaceSession($store, $request->requestId);

        requestRaceExpectWait($request->requestId, fn () => requestRacePrepare($store, $request->requestId, $session));

        expect(DB::table('payments.attempts')->count())->toBe(0);

        $attempt = requestRacePrepare($store, $request->requestId, $session);

        expect($attempt)->not->toBe('')
            ->and(app(PaymentsApi::class)->withdraw(REQUEST_RACE_ORDER))->toBeFalse();
    })->skip(fn (): bool => requestRaceInParallel(), 'Empties its database: run serially.');

    it('makes the end of a request past its time wait on it', function () {
        ['request' => $request] = requestRaceReady();
        CarbonImmutable::setTestNow('2027-01-01T13:00:00Z');
        Carbon::setTestNow('2027-01-01T13:00:00Z');

        requestRaceExpectWait($request->requestId, fn () => app(PaymentsApi::class)->openRequest(REQUEST_RACE_ORDER));

        expect(app(PaymentsApi::class)->openRequest(REQUEST_RACE_ORDER))->toBeNull();
    })->skip(fn (): bool => requestRaceInParallel(), 'Empties its database: run serially.');
});
