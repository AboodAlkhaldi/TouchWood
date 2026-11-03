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
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Sales\Application\Checkout\TryRelease;
use Modules\Sales\Application\Command\FollowCheckoutPayment\FollowCheckoutPayment;
use Modules\Sales\Application\Command\FollowCheckoutPayment\FollowCheckoutPaymentHandler;
use Modules\Sales\Application\Command\OpenCheckoutPayment\OpenCheckoutPayment;
use Modules\Sales\Application\Command\OpenCheckoutPayment\OpenCheckoutPaymentHandler;
use Modules\Sales\Application\Command\PayOnline\OnlineStart;
use Modules\Sales\Application\Command\PayOnline\PayOnline;
use Modules\Sales\Application\Command\PayOnline\PayOnlineHandler;
use Modules\Sales\Application\Quote\QuoteView;
use Modules\Sales\Domain\Exception\PaymentInProgress;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Payments\Support\FakeMyFatoorah;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Paying online at the same moment as something else (sales.md §9.5 #4; payments.md §1.1 rule 3, §1.8): another
| connection holds a row, and the call under test must wait on exactly that lock, which shows two of them can never
| pass each other. Then, once the other is done, the outcome is still one try and one order.
|
| Outside RefreshDatabase's transaction (lesson 87): it commits its own rows and empties the database before and
| after itself; skipped under --parallel.
*/

function triesRaceInParallel(): bool
{
    return ParallelTesting::token() !== false;
}

beforeEach(function () {
    if (triesRaceInParallel()) {
        return;
    }

    Artisan::call('migrate:fresh');
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    config(['database.connections.tries_race' => config('database.connections.'.config('database.default'))]);
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

    if (triesRaceInParallel()) {
        return;
    }

    DB::connection('tries_race')->disconnect();
    DB::statement('SET lock_timeout = 0');
    Cache::flush();
    app()->forgetScopedInstances();
    Artisan::call('migrate:fresh');
});

/**
 * @return array{fake: FakeMyFatoorah, quote: QuoteView, address: string}
 */
function triesRaceReady(): array
{
    $fake = FakeMyFatoorah::install();
    Cx::asSuperAdmin(function (): void {
        app(ChangeKeysHandler::class)->handle(new ChangeKeys(Fx::storeId('sa'), 'LIVE', 'sk_key_ABC123', 'whsec_secret_1', '2030-12-31'));
        app(SwitchGatewayHandler::class)->handle(new SwitchGateway(Fx::storeId('sa'), true));
    });
    ['address' => $address] = Cx::ready();

    return ['fake' => $fake, 'quote' => Cx::start($address, 'ONLINE'), 'address' => $address];
}

function triesRaceSession(QuoteView $quote): string
{
    return app(OpenCheckoutPaymentHandler::class)->handle(new OpenCheckoutPayment(Fx::storeId('sa'), $quote->id, 'en'))->sessionId;
}

function triesRacePay(QuoteView $quote, string $session): OnlineStart
{
    return app(PayOnlineHandler::class)->handle(new PayOnline(Fx::storeId('sa'), $quote->id, $session, 'en', 'https://shop.example.test/return'));
}

/**
 * Runs `$work` while another connection holds a row, and answers what it waited on.
 *
 * @param  Closure(): mixed  $work
 */
function triesRaceWaitsOn(string $table, string $id, Closure $work): QueryException
{
    $other = DB::connection('tries_race');
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

function triesRaceExpectWait(QueryException $waited, string $table): void
{
    expect($waited->getCode())->toBe('55P03')
        ->and(strtolower($waited->getSql()))->toContain($table)
        ->and(strtolower($waited->getSql()))->toContain('for update');
}

describe('at the same moment', function () {
    it('makes a second Pay of a quote wait, then answers the same try with one charge', function () {
        ['fake' => $fake, 'quote' => $quote] = triesRaceReady();
        $session = triesRaceSession($quote);

        triesRaceExpectWait(triesRaceWaitsOn('sales.quotes', $quote->id, fn () => triesRacePay($quote, $session)), '"sales"."quotes"');
        expect(DB::table('sales.checkout_tries')->count())->toBe(0);

        $first = triesRacePay($quote, $session);
        $second = triesRacePay($quote, $session);

        expect($second->tryId)->toBe($first->tryId)
            ->and(DB::table('sales.checkout_tries')->count())->toBe(1)
            ->and($fake->count('POST', '/v3/payments'))->toBe(1);
    })->skip(fn (): bool => triesRaceInParallel(), 'Empties its database: run serially.');

    it('makes Pay wait on the cart, so a change of the cart and a Pay can never pass each other', function () {
        ['quote' => $quote] = triesRaceReady();
        $session = triesRaceSession($quote);
        $cart = (string) DB::table('sales.carts')->value('id');

        triesRaceExpectWait(triesRaceWaitsOn('sales.carts', $cart, fn () => triesRacePay($quote, $session)), '"sales"."carts"');

        expect(DB::table('sales.checkout_tries')->count())->toBe(0)
            ->and(DB::table('inventory.holds')->count())->toBe(0);
    })->skip(fn (): bool => triesRaceInParallel(), 'Empties its database: run serially.');

    it('makes placing another quote wait on the cart, then refuses it while the try is live', function () {
        ['quote' => $quote, 'address' => $address] = triesRaceReady();
        $staffContact = Cx::start($address);
        $cart = (string) DB::table('sales.carts')->value('id');

        triesRaceExpectWait(triesRaceWaitsOn('sales.carts', $cart, fn () => Cx::place($staffContact->id)), '"sales"."carts"');
        triesRacePay($quote, triesRaceSession($quote));

        expect(fn () => Cx::place($staffContact->id))->toThrow(PaymentInProgress::class)
            ->and(DB::table('sales.orders')->count())->toBe(0);
    })->skip(fn (): bool => triesRaceInParallel(), 'Empties its database: run serially.');

    it('makes the page\'s completion wait on the try, then makes one order however often it is told', function () {
        ['fake' => $fake, 'quote' => $quote] = triesRaceReady();
        $paid = triesRacePay($quote, triesRaceSession($quote));
        $fake->paid($quote->totals->orderTotal->toDecimal(2));
        $follow = fn () => app(FollowCheckoutPaymentHandler::class)->handle(new FollowCheckoutPayment($paid->tryId));

        triesRaceExpectWait(triesRaceWaitsOn('sales.checkout_tries', $paid->tryId, $follow), '"sales"."checkout_tries"');
        expect(DB::table('sales.orders')->count())->toBe(0);

        $follow();
        $follow();

        expect(DB::table('sales.orders')->count())->toBe(1)
            ->and(DB::table('sales.payments')->count())->toBe(1);
    })->skip(fn (): bool => triesRaceInParallel(), 'Empties its database: run serially.');

    it('makes a release wait on the try, and a paid try then keeps its order and its stock', function () {
        ['fake' => $fake, 'quote' => $quote] = triesRaceReady();
        $paid = triesRacePay($quote, triesRaceSession($quote));
        $release = fn () => app(TryRelease::class)->release($paid->tryId);

        triesRaceExpectWait(triesRaceWaitsOn('sales.checkout_tries', $paid->tryId, $release), '"sales"."checkout_tries"');
        expect(DB::table('sales.checkout_tries')->value('state'))->toBe('LIVE');

        $fake->paid($quote->totals->orderTotal->toDecimal(2));
        app(FollowCheckoutPaymentHandler::class)->handle(new FollowCheckoutPayment($paid->tryId));
        $release();

        expect(DB::table('sales.checkout_tries')->value('state'))->toBe('PAID')
            ->and(DB::table('inventory.holds')->whereNull('ended_at')->count())->toBe(1);
    })->skip(fn (): bool => triesRaceInParallel(), 'Empties its database: run serially.');

    it('lets the unique index stop two live tries on one cart, whatever gets past the locks', function () {
        ['quote' => $quote] = triesRaceReady();
        $paid = triesRacePay($quote, triesRaceSession($quote));
        $row = (array) DB::table('sales.checkout_tries')->where('id', $paid->tryId)->sole();

        expect(fn () => DB::table('sales.checkout_tries')->insert([...$row, 'id' => '01arz3ndektsv4rrffq69g5fav', 'order_id' => '01arz3ndektsv4rrffq69g5fa1', 'quote_id' => '01arz3ndektsv4rrffq69g5fa2']))
            ->toThrow(QueryException::class, 'checkout_tries_live_cart');
    })->skip(fn (): bool => triesRaceInParallel(), 'Empties its database: run serially.');
});
