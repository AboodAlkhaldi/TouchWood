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
| customer per store, made only if absent, locked and its dead lots expired before anything changes;
| live lots in the order they are spent; a read never counts a dead lot and never writes.
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

/** The account, made, locked and swept inside a transaction of the test's own. */
function loyaltyAccountsOpen(string $customerId, CarbonImmutable $now, string $code = 'sa'): PointsAccount
{
    return DB::transaction(fn (): PointsAccount => loyaltyAccounts()->lockOrCreate($customerId, loyaltyAccountsStore($code), $now));
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
    $now = CarbonImmutable::now();

    $first = loyaltyAccountsOpen($customer, $now);
    // The id as a caller may write it: the column compares exactly, so the repository lowercases.
    $again = loyaltyAccountsOpen(strtoupper($customer), $now);
    $egypt = loyaltyAccountsOpen($customer, $now, 'eg');

    expect($again->id)->toBe($first->id)
        ->and($first->balance)->toBe(0)
        ->and($egypt->id)->not->toBe($first->id)
        ->and(DB::table('loyalty.accounts')->where('customer_id', $customer)->count())->toBe(2)
        ->and(loyaltyAccounts()->find($customer, loyaltyAccountsStore('sa'))?->id)->toBe($first->id)
        ->and(loyaltyAccounts()->find(strtoupper($customer), loyaltyAccountsStore('sa'))?->id)->toBe($first->id)
        ->and(loyaltyAccounts()->find($customer, loyaltyAccountsStore('ae')))->toBeNull()
        ->and(loyaltyAccounts()->find('not-a-ulid', loyaltyAccountsStore('sa')))->toBeNull();
});

it('refuses an account for an id that is not a ULID', function () {
    expect(fn () => loyaltyAccountsOpen('not-a-ulid', CarbonImmutable::now()))->toThrow(LogicException::class, 'customer\'s ULID')
        ->and(DB::table('loyalty.accounts')->count())->toBe(0);
});

it('locks the account inside the change\'s own transaction, and then its dead lots in spending order', function () {
    $customer = Fx::customer();
    $now = CarbonImmutable::parse('2026-06-01 12:00:00', 'UTC');
    $account = loyaltyAccountsOpen($customer, $now);
    loyaltyAccountsLot($account, 40, $now->subDay());
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = ['sql' => strtolower($query->sql), 'level' => DB::transactionLevel()];
    });

    loyaltyAccountsOpen($customer, $now);

    $locks = array_values(array_filter($queries, static fn (array $q): bool => str_contains($q['sql'], 'for update')));

    // RefreshDatabase holds level 1; the change's own transaction is level 2 (lesson 110). The
    // account first, then its lots, read in the order they are spent (lesson 37).
    expect(count($locks))->toBe(2)
        ->and($locks[0]['sql'])->toContain('"loyalty"."accounts"')
        ->and($locks[0]['level'])->toBe(2)
        ->and($locks[1]['sql'])->toContain('"loyalty"."lots"')
        ->and($locks[1]['sql'])->toContain('order by "expires_at" asc, "id" asc')
        ->and($locks[1]['level'])->toBe(2);
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

    expect(fn () => (new DatabasePointsAccounts($db))->lockOrCreate(Fx::customer(), loyaltyAccountsStore('sa'), CarbonImmutable::now()))
        ->toThrow(LogicException::class, 'inside its change');
});

it('reads the live lots in the order they are spent, and only those', function () {
    $now = CarbonImmutable::parse('2026-06-01 12:00:00', 'UTC');
    $account = loyaltyAccountsOpen(Fx::customer(), $now);
    $queries = [];

    $later = loyaltyAccountsLot($account, 100, $now->addMonths(6));
    $sooner = loyaltyAccountsLot($account, 50, $now->addMonths(1));
    $sameDay = loyaltyAccountsLot($account, 30, $now->addMonths(1));
    loyaltyAccountsLot($account, 40, $now->subDay());
    loyaltyAccountsLot($account, 20, $now->addMonths(2), left: 0);
    // A lot whose date is exactly now has passed: live means the date is still ahead.
    loyaltyAccountsLot($account, 10, $now);

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });
    $lots = loyaltyAccounts()->liveLots($account, $now);
    $balance = loyaltyAccounts()->liveBalance($account, $now);

    expect(array_map(static fn (Lot $lot): int => $lot->id, $lots))->toBe([$sooner, $sameDay, $later])
        ->and($balance)->toBe(180)
        // Equal dates: the older lot first, by its id — asked of the database, not left to it.
        ->and(implode("\n", $queries))->toContain('order by "expires_at" asc, "id" asc')
        // A read writes nothing and locks nothing.
        ->and(implode("\n", $queries))->not->toContain('for update')
        ->and(array_filter($queries, static fn (string $sql): bool => ! str_starts_with($sql, 'select')))->toBe([])
        ->and(array_map(static fn (Lot $lot): bool => $lot->isLiveAt($now), $lots))->toBe([true, true, true]);
});

it('expires each dead lot\'s points once, as one entry with its movement, and answers the account as it is after', function () {
    $now = CarbonImmutable::parse('2026-06-01 12:00:00', 'UTC');
    $account = loyaltyAccountsOpen(Fx::customer(), $now->subYear());
    $dead = loyaltyAccountsLot($account, 100, $now->subDay(), left: 30);
    $deadToo = loyaltyAccountsLot($account, 20, $now->subMonth());
    // A lot whose date is exactly now is dead: neither live nor left behind.
    $deadNow = loyaltyAccountsLot($account, 10, $now);
    loyaltyAccountsLot($account, 50, $now->addMonth());
    $emptyDead = loyaltyAccountsLot($account, 20, $now->subMonth(), left: 0);
    $before = (int) DB::table('loyalty.accounts')->where('id', $account->id)->value('balance');

    $after = loyaltyAccountsOpen($account->customerId, $now);

    $entries = DB::table('loyalty.entries')->where('account_id', $account->id)->where('kind', 'EXPIRED')->orderBy('id')->get()->all();

    expect($before)->toBe(110)
        ->and($after->balance)->toBe(50)
        ->and((int) DB::table('loyalty.accounts')->where('id', $account->id)->value('balance'))->toBe(50)
        // One entry per lot, the one expiring first first.
        ->and(array_map(static fn (stdClass $entry): int => (int) $entry->points, $entries))->toBe([-20, -30, -10])
        // Dated the moment of the write.
        ->and(array_map(static fn (stdClass $entry): string => CarbonImmutable::parse((string) $entry->occurred_at)->toIso8601String(), $entries))
        ->toBe(array_fill(0, 3, $now->toIso8601String()));

    foreach ([$deadToo => -20, $dead => -30, $deadNow => -10] as $lot => $points) {
        expect((int) DB::table('loyalty.lots')->where('id', $lot)->value('points_left'))->toBe(0)
            ->and(DB::table('loyalty.lot_movements')->where('lot_id', $lot)->where('points', $points)->count())->toBe(1);
    }

    // A lot already empty needs nothing.
    expect(DB::table('loyalty.lot_movements')->where('lot_id', $emptyDead)->count())->toBe(1);

    // A second write finds nothing left to expire.
    $again = loyaltyAccountsOpen($account->customerId, $now);

    expect($again->balance)->toBe(50)
        ->and(DB::table('loyalty.entries')->where('account_id', $account->id)->where('kind', 'EXPIRED')->count())->toBe(3);
});

it('reads a moment in any timezone as the same moment', function () {
    $account = loyaltyAccountsOpen(Fx::customer(), CarbonImmutable::parse('2025-01-01', 'UTC'));
    // The lot dies at midnight UTC; 02:30 in Riyadh is 23:30 UTC the day before, so it still lives.
    $lot = loyaltyAccountsLot($account, 70, CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'));
    $riyadh = CarbonImmutable::parse('2026-01-01 02:30:00', 'Asia/Riyadh');

    expect(loyaltyAccounts()->liveBalance($account, $riyadh))->toBe(70)
        ->and(array_map(static fn (Lot $live): int => $live->id, loyaltyAccounts()->liveLots($account, $riyadh)))->toBe([$lot])
        ->and(loyaltyAccountsOpen($account->customerId, $riyadh)->balance)->toBe(70)
        // An hour later in Riyadh it is 00:30 UTC: dead.
        ->and(loyaltyAccounts()->liveBalance($account, $riyadh->addHour()))->toBe(0)
        ->and(loyaltyAccounts()->liveLots($account, $riyadh->addHour()))->toBe([]);
});
