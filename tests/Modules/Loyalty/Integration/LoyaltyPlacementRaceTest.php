<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Loyalty\Application\Command\Redeem\Redeem;
use Modules\Loyalty\Application\Command\Redeem\RedeemHandler;
use Modules\Loyalty\Application\Points\CallerCheck;
use Modules\Loyalty\Application\Points\ProgrammeReader;
use Modules\Loyalty\Domain\Exception\RedemptionRefused;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Loyalty\Infrastructure\Eloquent\DatabasePointsAccounts;
use Modules\Loyalty\Infrastructure\Eloquent\DatabasePointsLedger;
use Modules\Loyalty\Public\Dto\RedeemPoints;
use Modules\Loyalty\Public\Dto\RedemptionQuote;
use Modules\Loyalty\Public\Enums\Audience;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;

use function Pest\Laravel\seed;

/*
| Two placements at once on one balance (loyalty.md §1.1, §8 Redeeming): one goes through, the other
| waits for the account's lock, works the redemption out again on what is left, and is refused — never
| below 0.
|
| The only Loyalty test outside RefreshDatabase's transaction (lesson 87): a second connection sees
| only committed rows, so this test commits its own, and empties the database before and after itself
| with migrate:fresh — leaving it migrated and empty, as every other test expects. Under --parallel
| it would run on the base database every process shares (it uses no database trait), so it is skipped.
*/

function loyaltyRaceInParallel(): bool
{
    return ParallelTesting::token() !== false;
}

beforeEach(function () {
    if (loyaltyRaceInParallel()) {
        return;
    }

    Artisan::call('migrate:fresh');
    seed(PlatformSeeder::class);
    config(['database.connections.loyalty_race' => config('database.connections.'.config('database.default'))]);
    Lx::standInForSales();
});

afterEach(function () {
    if (loyaltyRaceInParallel()) {
        return;
    }

    DB::connection('loyalty_race')->disconnect();
    Artisan::call('migrate:fresh');
});

function loyaltyRaceRedeem(string $customerId, RedemptionQuote $quote): Redeem
{
    $orderId = Lx::orderId();

    return new Redeem(new RedeemPoints(
        $orderId, 'T-'.substr($orderId, -8), $customerId, Fx::storeId('sa'), $quote->pointsCharged, $quote->discount,
        Audience::Public, Lx::money(100_000), Lx::placing(),
    ));
}

it('lets one of two placements at once through, and refuses the other; never below 0', function () {
    Lx::programme();
    $customer = Fx::customer();
    Lx::deliver($customer, 30_000);
    // Both checkouts quoted the same 300 points.
    $quote = Lx::quote($customer, 300, 100_000);
    Fx::actAsCustomer($customer);

    $first = DB::connection();
    $second = DB::connection('loyalty_race');
    // The second placement's own repositories, on its own connection.
    $other = new RedeemHandler(
        app(CallerCheck::class), app(ProgrammeReader::class), app(AccessApi::class),
        new DatabasePointsAccounts($second), new DatabasePointsLedger($second),
    );
    $secondOrder = loyaltyRaceRedeem($customer, $quote);
    $second->statement("SET lock_timeout = '500ms'");

    $first->beginTransaction();

    try {
        // The first placement has taken the account's lock and changed nothing yet.
        app(PointsAccounts::class)->lockOrCreate($customer, StoreId::fromString(Fx::storeId('sa')), CarbonImmutable::now());

        // The second, at that moment, stops on the account's lock itself — not on any row the first
        // has written — and gives up (55P03, lock_not_available).
        $waited = null;

        try {
            $second->transaction(fn () => $other->handle($secondOrder));
        } catch (QueryException $timedOut) {
            $waited = $timedOut;
        }

        $sql = strtolower(str_replace('"', '', (string) $waited?->getSql()));

        expect($waited?->getCode())->toBe('55P03')
            ->and($sql)->toContain('from loyalty.accounts')
            ->and($sql)->toContain('for update');

        // The first goes through, and commits.
        app(RedeemHandler::class)->handle(loyaltyRaceRedeem($customer, $quote));
        $first->commit();
    } catch (Throwable $failed) {
        $first->rollBack();

        throw $failed;
    }

    // Once it may, the second works it out again on the balance left: nothing.
    expect(fn () => $second->transaction(fn () => $other->handle($secondOrder)))
        ->toThrow(RedemptionRefused::class, 'NOTHING_TO_REDEEM')
        ->and(DB::table('loyalty.entries')->where('kind', 'REDEEMED')->count())->toBe(1)
        ->and((int) DB::table('loyalty.accounts')->where('customer_id', $customer)->value('balance'))->toBe(0)
        ->and((int) DB::table('loyalty.lots')->sum('points_left'))->toBe(0);
})->skip(fn (): bool => loyaltyRaceInParallel(), 'Empties its database: run serially.');
