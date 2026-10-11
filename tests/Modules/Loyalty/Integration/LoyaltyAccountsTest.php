<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Domain\Model\Lot;
use Modules\Loyalty\Domain\Model\PointsAccount;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Loyalty\Infrastructure\Eloquent\DatabasePointsAccounts;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| A customer's points account in a store and its lots (loyalty.md §1.1, §1.3, §1.10): one account per
| customer per store, made only if absent and locked before anything changes; live lots in the order
| they are spent; dead lots expired by a write, once, and never counted by a read.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function loyaltyAccounts(): PointsAccounts
{
    return app(PointsAccounts::class);
}

function loyaltyAccountsStore(string $code): StoreId
{
    return StoreId::fromString(Fx::storeId($code));
}

/** The account, made and locked inside a transaction of the test's own. */
function loyaltyAccountsOpen(string $customerId, string $code = 'sa'): PointsAccount
{
    return DB::transaction(fn (): PointsAccount => loyaltyAccounts()->lockOrCreate($customerId, loyaltyAccountsStore($code)));
}

/**
 * A lot of points added by hand, as the ledger would hold it, with its balance.
 */
function loyaltyAccountsLot(PointsAccount $account, int $points, CarbonImmutable $expiresAt, ?int $left = null): int
{
    $entry = DB::table('loyalty.entries')->insertGetId([
        'account_id' => $account->id, 'store_id' => $account->store->value, 'kind' => 'ADDED', 'points' => $points,
        'staff_id' => Fx::staff(firstName: 'Lot'.random_int(1, 1_000_000)), 'reason' => 'Goodwill',
    ]);
    $lot = DB::table('loyalty.lots')->insertGetId([
        'account_id' => $account->id, 'entry_id' => $entry, 'points' => $points, 'points_left' => $left ?? $points, 'expires_at' => $expiresAt,
    ]);
    DB::table('loyalty.lot_movements')->insert(['account_id' => $account->id, 'lot_id' => $lot, 'entry_id' => $entry, 'points' => $points]);
    DB::table('loyalty.accounts')->where('id', $account->id)->increment('balance', $left ?? $points);

    return $lot;
}

it('makes one account per customer per store, however often it is asked for', function () {
    $customer = Fx::customer();

    $first = loyaltyAccountsOpen($customer);
    $again = loyaltyAccountsOpen($customer);
    $egypt = loyaltyAccountsOpen($customer, 'eg');

    expect($again->id)->toBe($first->id)
        ->and($first->balance)->toBe(0)
        ->and($egypt->id)->not->toBe($first->id)
        ->and(DB::table('loyalty.accounts')->where('customer_id', $customer)->count())->toBe(2)
        ->and(loyaltyAccounts()->find($customer, loyaltyAccountsStore('sa'))?->id)->toBe($first->id)
        ->and(loyaltyAccounts()->find($customer, loyaltyAccountsStore('ae')))->toBeNull()
        ->and(loyaltyAccounts()->find('not-a-ulid', loyaltyAccountsStore('sa')))->toBeNull();
});

it('locks the account inside the change\'s own transaction, before anything else', function () {
    $customer = Fx::customer();
    $locks = [];
    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (str_contains($query->sql, 'loyalty"."accounts') && str_contains(strtolower($query->sql), 'for update')) {
            $locks[] = DB::transactionLevel();
        }
    });

    // The positive control: the lock is seen at all.
    loyaltyAccountsOpen($customer);

    // RefreshDatabase holds level 1; the change's own transaction is level 2 (lesson 110).
    expect($locks)->toBe([2]);
});

// RefreshDatabase holds a transaction through every test, so a connection with none is given
// directly (lesson 87): a lock outside a transaction would end with its statement.
it('refuses to lock an account outside a transaction', function () {
    // Laravel's own connection, with no database behind it and no transaction open: the guard must
    // refuse before a single statement would reach it.
    $db = new class(static fn (): PDO => throw new LogicException('No database behind this connection.')) extends Connection
    {
        public function transactionLevel(): int
        {
            return 0;
        }
    };

    expect(fn () => (new DatabasePointsAccounts($db))->lockOrCreate(Fx::customer(), loyaltyAccountsStore('sa')))
        ->toThrow(LogicException::class, 'inside its change');
});

it('reads the live lots in the order they are spent, and only those', function () {
    $account = loyaltyAccountsOpen(Fx::customer());
    $now = CarbonImmutable::parse('2026-06-01 12:00:00', 'UTC');

    $later = loyaltyAccountsLot($account, 100, $now->addMonths(6));
    $sooner = loyaltyAccountsLot($account, 50, $now->addMonths(1));
    $sameDay = loyaltyAccountsLot($account, 30, $now->addMonths(1));
    loyaltyAccountsLot($account, 40, $now->subDay());
    loyaltyAccountsLot($account, 20, $now->addMonths(2), left: 0);
    // A lot whose date is exactly now has passed: live means the date is still ahead.
    loyaltyAccountsLot($account, 10, $now);

    expect(array_map(static fn (Lot $lot): int => $lot->id, loyaltyAccounts()->liveLots($account, $now)))->toBe([$sooner, $sameDay, $later])
        ->and(loyaltyAccounts()->liveBalance($account, $now))->toBe(180);
});

it('expires a dead lot\'s points once, as one entry with its movement, and lowers the balance', function () {
    $account = loyaltyAccountsOpen(Fx::customer());
    $now = CarbonImmutable::parse('2026-06-01 12:00:00', 'UTC');
    $dead = loyaltyAccountsLot($account, 100, $now->subDay(), left: 30);
    loyaltyAccountsLot($account, 50, $now->addMonth());
    $emptyDead = loyaltyAccountsLot($account, 20, $now->subMonth(), left: 0);
    $before = (int) DB::table('loyalty.accounts')->where('id', $account->id)->value('balance');

    $expired = DB::transaction(function () use ($account, $now): int {
        $locked = loyaltyAccounts()->lockOrCreate($account->customerId, $account->store);

        return loyaltyAccounts()->expireDeadLots($locked, $now);
    });

    $entries = DB::table('loyalty.entries')->where('account_id', $account->id)->where('kind', 'EXPIRED')->get();
    $entry = $entries->first() ?? throw new RuntimeException('No EXPIRED entry was written.');

    expect($expired)->toBe(30)
        ->and($entries)->toHaveCount(1)
        ->and((int) $entry->points)->toBe(-30)
        ->and((int) DB::table('loyalty.lot_movements')->where('entry_id', $entry->id)->where('lot_id', $dead)->value('points'))->toBe(-30)
        ->and((int) DB::table('loyalty.lots')->where('id', $dead)->value('points_left'))->toBe(0)
        ->and(DB::table('loyalty.lot_movements')->where('lot_id', $emptyDead)->count())->toBe(1)
        ->and((int) DB::table('loyalty.accounts')->where('id', $account->id)->value('balance'))->toBe($before - 30);

    // A second write finds nothing left to expire.
    $again = DB::transaction(fn (): int => loyaltyAccounts()->expireDeadLots(loyaltyAccounts()->lockOrCreate($account->customerId, $account->store), $now));

    expect($again)->toBe(0)
        ->and(DB::table('loyalty.entries')->where('account_id', $account->id)->where('kind', 'EXPIRED')->count())->toBe(1);
});

it('reads a moment in any timezone as the same moment', function () {
    $account = loyaltyAccountsOpen(Fx::customer());
    // The lot dies at midnight UTC; 02:30 in Riyadh is 23:30 UTC the day before, so it still lives.
    loyaltyAccountsLot($account, 70, CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'));
    $riyadh = CarbonImmutable::parse('2026-01-01 02:30:00', 'Asia/Riyadh');

    expect(loyaltyAccounts()->liveBalance($account, $riyadh))->toBe(70)
        ->and(DB::transaction(fn (): int => loyaltyAccounts()->expireDeadLots(loyaltyAccounts()->lockOrCreate($account->customerId, $account->store), $riyadh)))->toBe(0)
        // An hour later in Riyadh it is 00:30 UTC: dead.
        ->and(loyaltyAccounts()->liveBalance($account, $riyadh->addHour()))->toBe(0);
});
