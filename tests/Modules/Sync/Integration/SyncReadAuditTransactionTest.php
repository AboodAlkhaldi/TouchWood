<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Sync\Application\Command\RunRead\RunRead;
use Modules\Sync\Application\Command\RunRead\RunReadHandler;
use Modules\Sync\Application\Command\UnwireStore\UnwireStore;
use Modules\Sync\Application\Command\UnwireStore\UnwireStoreHandler;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Sync\Support\FakeProvider;

use function Pest\Laravel\seed;

/*
| A read's audit entry is written in a transaction of the read's own (platform.md §1.5: no audited change
| without its entry; DatabaseAuditLog refuses an entry outside one). A queued job runs in no transaction, so
| the read must open its own - found by stage 5's screens step 7's browser test, where a store's first read
| ran on the synchronous queue and its "read done" entry was refused. The suite's own transaction hides
| this, so each entry's depth is measured against the depth the read started at.
|
| The review of step 7 (2026-10-19): the read's transactions lock the connection before the read, in Unwire
| Store's order, so the two never deadlock; a failure - the last attempt's, or a worker gone - is failed,
| reported and audited in one transaction, so none of it stays without the rest; a read Unwire Store
| stopped is failed and audited once, by Unwire Store.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-19 10:00:00+00');
    Queue::fake();
    FakeProvider::bind();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('sa'), 'ODOO', 'https://odoo.test/api', 'key', 'secret'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * Runs the store's waiting read as its job does - or, with $failed, ends it as its job's last failed attempt
 * does - and answers how deep in transactions each of Sync's read audit entries was written, beyond where
 * the run began.
 *
 * @return array<string, int> action => the depth beyond the run's own start
 */
function syncAuditDepths(?string $failed = null): array
{
    $read = (string) DB::table('sync.reads')->where('state', 'QUEUED')->orderBy('queued_at')->value('id');
    $start = DB::transactionLevel();
    $depths = [];

    DB::listen(function (QueryExecuted $query) use (&$depths, $start): void {
        if (str_contains($query->sql, 'audit_entries')) {
            foreach ($query->bindings as $binding) {
                if (is_string($binding) && str_starts_with($binding, 'sync.read.')) {
                    $depths[$binding] = DB::transactionLevel() - $start;
                }
            }
        }
    });

    Fx::asSystem(fn () => $failed === null ? app(RunReadHandler::class)->handle(new RunRead($read)) : app(RunReadHandler::class)->failed($read, $failed));

    return $depths;
}

it("writes a done read's audit entry in a transaction of its own", function () {
    expect(syncAuditDepths())->toBe(['sync.read.done' => 1]);
});

it("writes a failed read's audit entry in a transaction of its own, the store failing and reported with it", function () {
    expect(syncAuditDepths('The provider answered 503.'))->toBe(['sync.read.failed' => 1])
        ->and(DB::table('sync.report_entries')->where('kind', 'READ_FAILED')->whereNull('closed_at')->count())->toBe(1);
});

it('fails a read Unwire Store stopped once, as Unwire Store failed it: no second failure, no audit entry of its own', function () {
    FakeProvider::bound()->whileReading = fn () => app(UnwireStoreHandler::class)->handle(new UnwireStore(Fx::storeId('sa')));
    $read = (string) DB::table('sync.reads')->where('state', 'QUEUED')->value('id');

    expect(syncAuditDepths())->toBe([])
        ->and(DB::table('sync.reads')->where('id', $read)->value('error'))->toBe('UNWIRED');
});

/**
 * The SQL a run sends, in order, lower-cased.
 *
 * @return list<string>
 */
function syncStatements(Closure $run): array
{
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });
    $run();

    return $statements;
}

/**
 * The place of the first statement matching, or -1.
 *
 * @param  list<string>  $statements
 */
function syncFirst(array $statements, Closure $matches): int
{
    foreach ($statements as $at => $sql) {
        if ($matches($sql)) {
            return $at;
        }
    }

    return -1;
}

function syncLocksConnection(string $sql): bool
{
    return str_contains($sql, 'sync"."connections') && str_contains($sql, 'for update');
}

function syncWritesRead(string $sql): bool
{
    return str_starts_with($sql, 'update "sync"."reads"');
}

it("locks the connection before it finishes the read - Unwire Store's order, so the two never deadlock", function () {
    $read = (string) DB::table('sync.reads')->where('state', 'QUEUED')->value('id');
    $statements = syncStatements(fn () => Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($read))));
    $finish = syncFirst($statements, static fn (string $sql): bool => syncWritesRead($sql) && str_contains($sql, '"prices_changed"'));

    expect($finish)->toBeGreaterThan(0)
        ->and(syncFirst(array_slice($statements, 0, $finish), syncLocksConnection(...)))->toBeGreaterThanOrEqual(0)
        ->and(DB::table('sync.reads')->where('id', $read)->value('state'))->toBe('DONE');

    // Unwire Store's own order, the one the read follows: the connection, then the reads.
    $unwire = syncStatements(fn () => app(UnwireStoreHandler::class)->handle(new UnwireStore(Fx::storeId('sa'))));

    expect(syncFirst($unwire, syncLocksConnection(...)))->toBeGreaterThanOrEqual(0)
        ->and(syncFirst($unwire, syncLocksConnection(...)))->toBeLessThan(syncFirst($unwire, syncWritesRead(...)));
});

it('locks the connection before it fails a read', function () {
    $read = (string) DB::table('sync.reads')->where('state', 'QUEUED')->value('id');
    $statements = syncStatements(fn () => Fx::asSystem(fn () => app(RunReadHandler::class)->failed($read, 'The provider answered 503.')));

    expect(syncFirst($statements, syncLocksConnection(...)))->toBeGreaterThanOrEqual(0)
        ->and(syncFirst($statements, syncLocksConnection(...)))->toBeLessThan(syncFirst($statements, syncWritesRead(...)));
});

/** A read of the store running since 31 minutes ago - its worker gone. */
function syncStaleRead(): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('sync.reads')->insert(['id' => $id, 'store_id' => Fx::storeId('sa'), 'kind' => 'NIGHTLY', 'state' => 'RUNNING', 'queued_at' => now()->subMinutes(32), 'started_at' => now()->subMinutes(31)]);

    return $id;
}

/** Throws as a read's failure is audited: whatever its transaction wrote must not stay. */
function syncAuditFails(): void
{
    DB::listen(function (QueryExecuted $query): void {
        if (str_contains($query->sql, 'audit_entries') && in_array('sync.read.failed', $query->bindings, true)) {
            throw new RuntimeException('The audit log is down.');
        }
    });
}

it('fails a read whose worker went away (WORKER_GONE), reported and audited with it', function () {
    $stale = syncStaleRead();
    $read = (string) DB::table('sync.reads')->where('state', 'QUEUED')->value('id');

    Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($read)));

    expect([DB::table('sync.reads')->where('id', $stale)->value('state'), DB::table('sync.reads')->where('id', $stale)->value('error')])->toBe(['FAILED', 'WORKER_GONE'])
        ->and(DB::table('platform.audit_entries')->where('action', 'sync.read.failed')->where('subject_id', $stale)->count())->toBe(1)
        ->and(DB::table('sync.read_reports')->where('read_id', $stale)->where('kind', 'READ_FAILED')->count())->toBe(1);
});

it('keeps a read whose worker went away running when its failure cannot be recorded, so the next read finds it again', function () {
    $stale = syncStaleRead();
    $read = (string) DB::table('sync.reads')->where('state', 'QUEUED')->value('id');
    syncAuditFails();

    expect(fn () => Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($read))))->toThrow(RuntimeException::class);

    expect(DB::table('sync.reads')->where('id', $stale)->value('state'))->toBe('RUNNING')
        ->and(DB::table('sync.report_entries')->where('kind', 'READ_FAILED')->count())->toBe(0)
        ->and(DB::table('sync.connections')->where('store_id', Fx::storeId('sa'))->value('state'))->toBe('OK');
});

it('commits nothing of a failed read when its failure cannot be recorded', function () {
    $read = (string) DB::table('sync.reads')->where('state', 'QUEUED')->value('id');
    syncAuditFails();

    expect(fn () => Fx::asSystem(fn () => app(RunReadHandler::class)->failed($read, 'The provider answered 503.')))->toThrow(RuntimeException::class);

    expect(DB::table('sync.reads')->where('id', $read)->value('state'))->toBe('QUEUED')
        ->and(DB::table('sync.report_entries')->where('kind', 'READ_FAILED')->count())->toBe(0)
        ->and(DB::table('sync.connections')->where('store_id', Fx::storeId('sa'))->value('state'))->toBe('OK');
});
