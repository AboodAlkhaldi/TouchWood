<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sync\Infrastructure\Persistence\SyncSchema;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| The sync schema and its three tables (sync.md §5): what the database refuses behind the code.
*/

uses(RefreshDatabase::class);

it('creates the sync schema, on the search path so a fresh migration wipes it', function () {
    expect(DB::table('information_schema.schemata')->where('schema_name', 'sync')->exists())->toBeTrue()
        ->and(explode(',', (string) config('database.connections.pgsql.search_path')))->toContain('sync');
});

// A test database keeps its schemas from one run to the next (migrate:fresh drops tables, never
// schemas), so the test above cannot tell whether the migration still creates it - CI, starting from
// an empty database, can. This one starts from nothing and runs SyncSchema, which the migration calls;
// PostgreSQL's DDL is transactional, so the test's own transaction puts everything back (as
// Inventory's schema test).
it('creates the schema from nothing, and its rollback drops it with what is in it', function () {
    DB::statement('DROP SCHEMA sync CASCADE');

    SyncSchema::create();

    expect(DB::table('information_schema.schemata')->where('schema_name', 'sync')->exists())->toBeTrue();

    // Something in it, so a rollback without CASCADE would be refused.
    DB::statement('CREATE TABLE sync.rollback_probe (id int)');
    SyncSchema::drop();

    expect(DB::table('information_schema.schemata')->where('schema_name', 'sync')->exists())->toBeFalse();
});

describe('what the database refuses behind the code', function () {
    beforeEach(function () {
        seed(PlatformSeeder::class);
    });

    it('keeps a connection to one known provider, at an HTTPS address, its fields an object, and a failing one saying why', function () {
        $row = [
            'store_id' => Fx::storeId('sa'), 'provider' => 'ODOO', 'address' => 'https://provider.test/items', 'settings' => '{}',
            'key_encrypted' => 'k', 'secret_encrypted' => 's', 'state' => 'OK', 'last_error' => null,
            'created_by' => strtolower((string) Str::ulid()), 'created_at' => now(), 'updated_at' => now(),
        ];
        $insert = static fn (array $changed): Closure => fn () => DB::transaction(fn () => DB::table('sync.connections')->insert([...$row, ...$changed]));

        expect($insert(['provider' => 'SAP']))->toThrow(QueryException::class, 'connections_provider')
            ->and($insert(['address' => 'http://provider.test/items']))->toThrow(QueryException::class, 'connections_address')
            ->and($insert(['settings' => '[]']))->toThrow(QueryException::class, 'connections_settings')
            ->and($insert(['state' => 'BROKEN']))->toThrow(QueryException::class, 'connections_state')
            ->and($insert(['state' => 'FAILING']))->toThrow(QueryException::class, 'connections_last_error')
            ->and($insert(['last_error' => 'Timed out']))->toThrow(QueryException::class, 'connections_last_error')
            // Every connection has both times (Laravel's timestampsTz() would let them be empty).
            ->and($insert(['created_at' => null]))->toThrow(QueryException::class, 'created_at')
            ->and($insert(['updated_at' => null]))->toThrow(QueryException::class, 'updated_at');

        $insert([])();

        // One connection per store.
        expect($insert(['state' => 'FAILING', 'last_error' => 'Timed out']))->toThrow(QueryException::class, 'connections_pkey')
            ->and(DB::table('sync.connections')->count())->toBe(1);
    });

    it('keeps each read of a known kind and state, its counts from 0, its times in step with its state, and its asker staff', function () {
        $row = [
            'store_id' => Fx::storeId('sa'), 'kind' => 'HOURLY', 'state' => 'QUEUED', 'error' => null,
            'requested_by_type' => null, 'requested_by_id' => null, 'queued_at' => now(), 'started_at' => null, 'finished_at' => null,
        ];
        $insert = static fn (array $changed): Closure => fn () => DB::transaction(fn () => DB::table('sync.reads')->insert([...$row, 'id' => strtolower((string) Str::ulid()), ...$changed]));
        $staff = ['requested_by_type' => 'STAFF', 'requested_by_id' => strtolower((string) Str::ulid())];
        $done = ['state' => 'DONE', 'started_at' => now(), 'finished_at' => now()];
        $failed = ['state' => 'FAILED', 'started_at' => now(), 'finished_at' => now(), 'error' => 'Timed out'];

        expect($insert(['kind' => 'WEEKLY']))->toThrow(QueryException::class, 'reads_kind')
            // An unknown state breaks reads_times too; PostgreSQL checks the rules in name order.
            ->and($insert(['state' => 'LOST']))->toThrow(QueryException::class, 'reads_state')
            ->and($insert(['prices_changed' => -1]))->toThrow(QueryException::class, 'reads_counts')
            ->and($insert(['stock_changed' => -1]))->toThrow(QueryException::class, 'reads_counts')
            ->and($insert(['skipped' => -1]))->toThrow(QueryException::class, 'reads_counts')
            // Both halves of the asker or neither, only staff, never for the provider's notices or the hour.
            ->and($insert(['kind' => 'NOW', 'requested_by_type' => 'STAFF']))->toThrow(QueryException::class, 'reads_requested_by')
            ->and($insert(['kind' => 'NOW', 'requested_by_id' => strtolower((string) Str::ulid())]))->toThrow(QueryException::class, 'reads_requested_by')
            ->and($insert(['kind' => 'NOW', 'requested_by_type' => 'CUSTOMER', 'requested_by_id' => strtolower((string) Str::ulid())]))->toThrow(QueryException::class, 'reads_requested_by')
            ->and($insert([...$staff, 'kind' => 'HOURLY']))->toThrow(QueryException::class, 'reads_requested_by')
            ->and($insert([...$staff, 'kind' => 'NOTIFIED', 'codes' => '["1"]']))->toThrow(QueryException::class, 'reads_requested_by')
            // Started once out of the queue.
            ->and($insert(['started_at' => now()]))->toThrow(QueryException::class, 'reads_times')
            ->and($insert(['state' => 'RUNNING']))->toThrow(QueryException::class, 'reads_times')
            ->and($insert([...$done, 'started_at' => null]))->toThrow(QueryException::class, 'reads_times')
            // Finished once done or failed, and only then.
            ->and($insert(['state' => 'RUNNING', 'started_at' => now(), 'finished_at' => now()]))->toThrow(QueryException::class, 'reads_finished')
            ->and($insert([...$done, 'finished_at' => null]))->toThrow(QueryException::class, 'reads_finished')
            ->and($insert([...$failed, 'finished_at' => null]))->toThrow(QueryException::class, 'reads_finished')
            // A failure says why; nothing else carries a reason.
            ->and($insert([...$failed, 'error' => null]))->toThrow(QueryException::class, 'reads_error')
            ->and($insert([...$done, 'error' => 'Timed out']))->toThrow(QueryException::class, 'reads_error');

        $insert([])();
        $insert([...$staff, 'kind' => 'NOW'])();
        $insert([...$staff, 'kind' => 'NIGHTLY'])();
        $insert([...$staff, 'kind' => 'CODE', 'codes' => '["123"]', 'state' => 'RUNNING', 'started_at' => now()])();
        $insert([...$done, 'kind' => 'CODE', 'codes' => '["123"]', 'prices_changed' => 1])();
        $insert([...$failed, 'kind' => 'NIGHTLY'])();
        // A read that never started: it gave up waiting, or its store was unwired.
        $insert([...$failed, 'started_at' => null])();

        expect(DB::table('sync.reads')->count())->toBe(7);
    });

    it('keeps each report entry about one thing, of the seven kinds, one open per store, kind, code and size, closed with who closed it', function () {
        $size = strtolower((string) Str::ulid());
        $row = [
            'store_id' => Fx::storeId('sa'), 'kind' => 'UNKNOWN_CODE', 'code' => 'TW-1', 'variant_id' => null, 'sale_id' => null, 'detail' => '{}',
            'opened_at' => now(), 'seen_at' => now(), 'closed_at' => null, 'closed_by' => null,
        ];
        $aSize = ['code' => null, 'variant_id' => $size];
        $insert = static fn (array $changed): Closure => fn () => DB::transaction(fn () => DB::table('sync.report_entries')->insert([...$row, ...$changed]));

        expect($insert([...$aSize, 'kind' => 'SOMETHING']))->toThrow(QueryException::class, 'report_entries_kind')
            ->and($insert(['detail' => '"text"']))->toThrow(QueryException::class, 'report_entries_detail')
            ->and($insert(['closed_at' => now()]))->toThrow(QueryException::class, 'report_entries_closed')
            ->and($insert(['closed_by' => 'READ']))->toThrow(QueryException::class, 'report_entries_closed')
            // A code we do not have: the code, never a size.
            ->and($insert(['code' => null]))->toThrow(QueryException::class, 'report_entries_subject')
            ->and($insert(['variant_id' => $size]))->toThrow(QueryException::class, 'report_entries_subject')
            // A failed read is the whole store's: neither.
            ->and($insert(['kind' => 'READ_FAILED']))->toThrow(QueryException::class, 'report_entries_subject')
            ->and($insert([...$aSize, 'kind' => 'READ_FAILED']))->toThrow(QueryException::class, 'report_entries_subject')
            // Every other kind is a size of ours: the size, never the code.
            ->and($insert(['kind' => 'BAD_VALUE']))->toThrow(QueryException::class, 'report_entries_subject')
            ->and($insert(['kind' => 'BAD_VALUE', 'variant_id' => $size]))->toThrow(QueryException::class, 'report_entries_subject')
            ->and($insert(['kind' => 'BAD_VALUE', 'code' => null]))->toThrow(QueryException::class, 'report_entries_subject')
            // Only a stopped sale names its sale, and it always does.
            ->and($insert([...$aSize, 'kind' => 'SALE_STOPPED']))->toThrow(QueryException::class, 'report_entries_sale')
            ->and($insert([...$aSize, 'kind' => 'BAD_VALUE', 'sale_id' => strtolower((string) Str::ulid())]))->toThrow(QueryException::class, 'report_entries_sale')
            // The provider's code is kept up to 255 characters; a longer one is cut before it is written.
            ->and($insert(['code' => str_repeat('C', 256)]))->toThrow(QueryException::class, 'too long');

        $insert([])();

        // The same thing again is the same entry - a code, a size, the store.
        expect($insert([]))->toThrow(QueryException::class, 'report_entries_open');

        $insert([...$aSize, 'kind' => 'NOT_IN_PROVIDER'])();

        expect($insert([...$aSize, 'kind' => 'NOT_IN_PROVIDER']))->toThrow(QueryException::class, 'report_entries_open');

        $insert(['code' => null, 'kind' => 'READ_FAILED'])();

        expect($insert(['code' => null, 'kind' => 'READ_FAILED']))->toThrow(QueryException::class, 'report_entries_open');

        // Another kind, another code, another store, or the first one closed: each its own entry.
        $insert([...$aSize, 'kind' => 'BAD_VALUE'])();
        $insert([...$aSize, 'kind' => 'SALE_STOPPED', 'sale_id' => strtolower((string) Str::ulid())])();
        $insert(['code' => 'TW-2'])();
        $insert(['code' => str_repeat('C', 255)])();
        $insert(['store_id' => Fx::storeId('ae')])();
        DB::table('sync.report_entries')->where('kind', 'UNKNOWN_CODE')->where('code', 'TW-1')->where('store_id', Fx::storeId('sa'))->update(['closed_at' => now(), 'closed_by' => 'READ']);
        $insert([])();

        expect(DB::table('sync.report_entries')->count())->toBe(9);
    });

    it('never lets a store with a connection, reads or entries go, and keeps a size\'s or a sale\'s entries when they go', function () {
        $rules = collect(DB::select(
            "SELECT conname, confdeltype FROM pg_constraint WHERE contype = 'f' AND connamespace = 'sync'::regnamespace ORDER BY conname",
        ))->mapWithKeys(static fn (stdClass $key): array => [(string) $key->conname => (string) $key->confdeltype])->all();

        // No key on variant_id or sale_id: the report names them as the audit log names a subject.
        expect($rules)->toBe([
            'connections_store' => 'r',
            'reads_store' => 'r',
            'report_entries_store' => 'r',
        ]);
    });
});
