<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

it('writes a read stopped by unwiring as failed, in a transaction of its own', function () {
    FakeProvider::bound()->whileReading = fn () => app(UnwireStoreHandler::class)->handle(new UnwireStore(Fx::storeId('sa')));

    expect(syncAuditDepths())->toBe(['sync.read.failed' => 1]);
});
