<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Domain\ValueObject\MovementReason;
use Modules\Inventory\Infrastructure\Persistence\InventorySchema;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| The inventory schema (inventory.md §5). The tables and their checks join this file with the steps
| that create them.
*/

uses(RefreshDatabase::class);

it('creates the inventory schema, on the search path so a fresh migration wipes it', function () {
    expect(DB::table('information_schema.schemata')->where('schema_name', 'inventory')->exists())->toBeTrue()
        ->and(explode(',', (string) config('database.connections.pgsql.search_path')))->toContain('inventory');
});

// A test database keeps its schemas from one run to the next (migrate:fresh drops tables, never
// schemas), so the test above cannot tell whether the migration still creates it - CI, starting from
// an empty database, can. This one starts from nothing and runs InventorySchema, which the migration
// calls; PostgreSQL's DDL is transactional, so the test's own transaction puts everything back (as
// Catalog's schema test).
it('creates the schema from nothing, and its rollback drops it with what is in it', function () {
    DB::statement('DROP SCHEMA inventory CASCADE');

    InventorySchema::create();

    expect(DB::table('information_schema.schemata')->where('schema_name', 'inventory')->exists())->toBeTrue();

    // Something in it, so a rollback without CASCADE would be refused.
    DB::statement('CREATE TABLE inventory.rollback_probe (id int)');
    InventorySchema::drop();

    expect(DB::table('information_schema.schemata')->where('schema_name', 'inventory')->exists())->toBeFalse();
});

it('stores its kinds as strings, never integers, each CHECK allowing exactly the code\'s values (§5)', function () {
    $kinds = DB::table('information_schema.columns')
        ->where('table_schema', 'inventory')
        ->whereIn('column_name', ['reason', 'actor_type'])
        ->orderBy('column_name')
        ->get(['column_name', 'data_type']);
    $definition = DB::selectOne('select pg_get_constraintdef(oid) as definition from pg_constraint where conname = ?', ['stock_movements_reason'])?->definition;
    preg_match('/ARRAY\[([^\]]*)\]/', (string) $definition, $list);
    preg_match_all("/'([A-Z_]+)'::/", $list[1] ?? '', $allowed);

    expect($kinds->map(static fn (stdClass $column): string => "{$column->column_name} {$column->data_type}")->all())->toBe(['actor_type character varying', 'reason character varying'])
        ->and($allowed[1])->toEqualCanonicalizing(array_map(static fn (MovementReason $reason): string => $reason->value, MovementReason::cases()));
});

describe('what the database refuses behind the code (§8 #13)', function () {
    beforeEach(function () {
        seed(PlatformSeeder::class);
        seed(CatalogSeeder::class);
    });

    it('keeps a size\'s stock and held at 0 or more, its returned extra too, and its threshold empty or 0 to 100,000', function () {
        $ready = Px::ready();
        $row = ['store_id' => Fx::storeId('sa'), 'variant_id' => $ready['variants'][0], 'product_id' => $ready['product'], 'updated_at' => now()];
        $insert = static fn (array $changed): Closure => fn () => DB::transaction(fn () => DB::table('inventory.stock')->insert([...$row, ...$changed]));

        expect($insert(['in_stock' => -1]))->toThrow(QueryException::class, 'stock_in_stock')
            ->and($insert(['held' => -1]))->toThrow(QueryException::class, 'stock_held')
            ->and($insert(['returned_extra' => -1]))->toThrow(QueryException::class, 'stock_returned_extra')
            ->and($insert(['threshold' => -1]))->toThrow(QueryException::class, 'stock_threshold')
            ->and($insert(['threshold' => 100001]))->toThrow(QueryException::class, 'stock_threshold');

        $insert(['threshold' => null])();

        expect($insert(['threshold' => 5]))->toThrow(QueryException::class, 'stock_pkey')
            ->and(DB::table('inventory.stock')->value('in_stock'))->toBe(0);
    });

    it('keeps a store\'s kept Low Stock count at 0 or more, counted with its time or neither', function () {
        $insert = static fn (array $row): Closure => fn () => DB::transaction(fn () => DB::table('inventory.low_counts')->insert(['store_id' => Fx::storeId('sa'), ...$row]));

        expect($insert(['low' => -1, 'counted_at' => now()]))->toThrow(QueryException::class, 'low_counts_low')
            ->and($insert(['low' => 1]))->toThrow(QueryException::class, 'low_counts_counted')
            ->and($insert(['counted_at' => now()]))->toThrow(QueryException::class, 'low_counts_counted');

        $insert(['low' => 0, 'counted_at' => now()])();

        expect(DB::table('inventory.low_counts')->value('recount_queued'))->toBeFalse();
    });

    it('keeps each ledger line\'s numbers adding up, its reason known, and a correction\'s note there', function () {
        $row = [
            'store_id' => Fx::storeId('sa'), 'variant_id' => Px::ready()['variants'][0], 'reason' => 'RECEIVED', 'change' => 2,
            'before' => 1, 'after' => 3, 'note' => null, 'actor_type' => 'SYSTEM', 'actor_id' => null,
        ];
        $insert = static fn (array $changed): Closure => fn () => DB::transaction(fn () => DB::table('inventory.stock_movements')->insert([...$row, 'reference' => (string) Str::ulid(), ...$changed]));

        expect($insert(['reason' => 'GIFT']))->toThrow(QueryException::class, 'stock_movements_reason')
            ->and($insert(['after' => 4]))->toThrow(QueryException::class, 'stock_movements_counts')
            ->and($insert(['before' => -1, 'change' => 4]))->toThrow(QueryException::class, 'stock_movements_counts')
            ->and($insert(['after' => -1, 'change' => -2]))->toThrow(QueryException::class, 'stock_movements_counts')
            ->and($insert(['reason' => 'CORRECTION']))->toThrow(QueryException::class, 'stock_movements_note')
            ->and($insert(['reason' => 'CORRECTION', 'note' => '  ']))->toThrow(QueryException::class, 'stock_movements_note')
            // Blank as PHP's trim() reads it: tabs and line ends too.
            ->and($insert(['reason' => 'CORRECTION', 'note' => "\t\r\n"]))->toThrow(QueryException::class, 'stock_movements_note')
            // With an id, so only the type is wrong (PostgreSQL checks the rules in name order).
            ->and($insert(['actor_type' => 'ROBOT', 'actor_id' => strtolower((string) Str::ulid())]))->toThrow(QueryException::class, 'stock_movements_actor_type')
            // Who made it, as the audit log: the system has no id, anyone else has one.
            ->and($insert(['actor_type' => 'STAFF']))->toThrow(QueryException::class, 'stock_movements_actor_id')
            ->and($insert(['actor_id' => strtolower((string) Str::ulid())]))->toThrow(QueryException::class, 'stock_movements_actor_id')
            // Whoever queued a job: both halves or neither, and only behind the system.
            ->and($insert(['requested_by_type' => 'STAFF']))->toThrow(QueryException::class, 'stock_movements_requested_by')
            ->and($insert(['requested_by_id' => strtolower((string) Str::ulid())]))->toThrow(QueryException::class, 'stock_movements_requested_by')
            ->and($insert(['actor_type' => 'STAFF', 'actor_id' => strtolower((string) Str::ulid()), 'requested_by_type' => 'STAFF', 'requested_by_id' => strtolower((string) Str::ulid())]))->toThrow(QueryException::class, 'stock_movements_requested_by')
            ->and($insert(['requested_by_type' => 'SYSTEM', 'requested_by_id' => strtolower((string) Str::ulid())]))->toThrow(QueryException::class, 'stock_movements_requested_by');

        $insert([])();
        $insert(['reason' => 'CORRECTION', 'note' => 'Found two more'])();
        $insert(['actor_type' => 'STAFF', 'actor_id' => strtolower((string) Str::ulid())])();
        $insert(['requested_by_type' => 'STAFF', 'requested_by_id' => strtolower((string) Str::ulid())])();

        expect(DB::table('inventory.stock_movements')->count())->toBe(4);
    });

    it('keeps each reason going its one way (owner, 2026-10-11)', function (string $reason, int $before, int $after, bool $kept, ?string $note = 'a note') {
        $row = [
            'store_id' => Fx::storeId('sa'), 'variant_id' => Px::ready()['variants'][0], 'reason' => $reason, 'change' => $after - $before,
            'before' => $before, 'after' => $after, 'note' => $note, 'reference' => (string) Str::ulid(), 'actor_type' => 'SYSTEM', 'actor_id' => null,
        ];
        $insert = fn () => DB::transaction(fn () => DB::table('inventory.stock_movements')->insert($row));

        if ($kept) {
            $insert();

            expect(DB::table('inventory.stock_movements')->count())->toBe(1);
        } else {
            expect($insert)->toThrow(QueryException::class, 'stock_movements_direction');
        }
    })->with([
        'received comes in' => ['RECEIVED', 1, 3, true],
        'received never goes out' => ['RECEIVED', 3, 1, false],
        'received is never nothing' => ['RECEIVED', 3, 3, false],
        'returned comes in' => ['RETURNED', 0, 1, true],
        'returned never goes out' => ['RETURNED', 1, 0, false],
        'damaged goes out' => ['DAMAGED_LOST', 3, 1, true],
        'damaged never comes in' => ['DAMAGED_LOST', 1, 3, false],
        'an offline sale goes out' => ['OFFLINE_SALE', 3, 2, true],
        'an offline sale never comes in' => ['OFFLINE_SALE', 2, 3, false],
        'a shipment goes out' => ['SHIPPED', 3, 2, true],
        'a shipment never comes in' => ['SHIPPED', 2, 3, false],
        'a shipment taking nothing notes its shortfall (owner, 2026-10-19)' => ['SHIPPED', 0, 0, true],
        'a shipment taking nothing without a note is refused' => ['SHIPPED', 0, 0, false, null],
        'a shipment taking nothing with a blank note is refused' => ['SHIPPED', 0, 0, false, " \t\n"],
        'a shipment taking nothing from a stock above 0 is refused, note or not' => ['SHIPPED', 5, 5, false],
        'a correction comes in' => ['CORRECTION', 1, 2, true],
        'a correction goes out' => ['CORRECTION', 2, 1, true],
        'a correction is never nothing' => ['CORRECTION', 2, 2, false],
        'a count finds it right' => ['STOCKTAKE', 2, 2, true],
        'a count goes down' => ['STOCKTAKE', 2, 0, true],
        'the file sets it' => ['FILE', 0, 9, true],
        'the provider sets it' => ['PROVIDER', 9, 4, true],
    ]);

    it('rolls the shortfall line\'s check back to the old one and forward again, refused back while such a line is kept (owner, 2026-10-19)', function () {
        $migration = require dirname(__DIR__, 4).'/src/Modules/Inventory/Infrastructure/Persistence/Migrations/2026_10_20_100000_allow_inventory_shipment_shortfall_line.php';
        $size = Px::ready()['variants'][0];
        $insert = static fn (): Closure => fn () => DB::transaction(fn () => DB::table('inventory.stock_movements')->insert([
            'store_id' => Fx::storeId('sa'), 'variant_id' => $size, 'reason' => 'SHIPPED', 'change' => 0, 'before' => 0, 'after' => 0,
            'note' => '2 more shipped than counted - recount', 'reference' => (string) Str::ulid(), 'actor_type' => 'SYSTEM', 'actor_id' => null,
        ]));

        // Each step in a savepoint of the test's transaction: PostgreSQL's DDL is transactional.
        DB::transaction(fn () => $migration->down());

        expect($insert())->toThrow(QueryException::class, 'stock_movements_direction')
            ->and(DB::selectOne('select convalidated from pg_constraint where conname = ?', ['stock_movements_direction'])?->convalidated)->toBeTrue();

        DB::transaction(fn () => $migration->up());
        $insert()();

        expect(DB::table('inventory.stock_movements')->count())->toBe(1)
            ->and(DB::selectOne('select convalidated from pg_constraint where conname = ?', ['stock_movements_direction'])?->convalidated)->toBeTrue()
            // The old check cannot come back over a 0 -> 0 line: the ledger is append-only.
            ->and(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class, 'stock_movements_direction');
    });

    it('keeps Inventory\'s own notes as a code it knows with its values, never beside a typed note, each counting as the note a line needs (stage 5 step 10)', function () {
        $size = Px::ready()['variants'][0];
        $insert = static fn (array $changed): Closure => fn () => DB::transaction(fn () => DB::table('inventory.stock_movements')->insert([
            'store_id' => Fx::storeId('sa'), 'variant_id' => $size, 'reason' => 'SHIPPED', 'change' => 0, 'before' => 0, 'after' => 0,
            'note' => null, 'note_code' => 'SHIPPED_BEYOND_COUNT', 'note_values' => '{"count": 2}', 'reference' => (string) Str::ulid(), 'actor_type' => 'SYSTEM', 'actor_id' => null,
            ...$changed,
        ]));

        expect($insert(['note_code' => 'RECOUNT']))->toThrow(QueryException::class, 'stock_movements_note_code')
            ->and($insert(['note_code' => null]))->toThrow(QueryException::class, 'stock_movements_note_values')
            ->and($insert(['note_values' => '[2]']))->toThrow(QueryException::class, 'stock_movements_note_values')
            ->and($insert(['note' => 'typed']))->toThrow(QueryException::class, 'stock_movements_note_one');

        // A shipment from 0 and a correction each say why with a code as with a note.
        $insert([])();
        $insert(['reason' => 'CORRECTION', 'change' => 2, 'after' => 2, 'note_code' => 'UNWIRED', 'note_values' => null])();

        expect(DB::table('inventory.stock_movements')->count())->toBe(2);
    });

    it('keeps a hold line\'s pieces from 1, shipped within them, an end it knows and a time only once ended, and a return\'s pieces from 1', function () {
        $size = Px::ready()['variants'][0];
        $hold = strtolower((string) Str::ulid());
        DB::table('inventory.holds')->insert(['id' => $hold, 'store_id' => Fx::storeId('sa'), 'order_id' => strtolower((string) Str::ulid()), 'wired' => false, 'created_at' => now()]);
        $line = ['hold_id' => $hold, 'variant_id' => $size, 'gift' => false, 'quantity' => 2, 'counts_on_stock' => true, 'shipped' => 0, 'ended' => 'OPEN', 'ended_at' => null];
        $insert = static fn (array $changed): Closure => fn () => DB::transaction(fn () => DB::table('inventory.hold_lines')->insert([...$line, ...$changed]));
        $return = ['return_id' => 'r-1', 'variant_id' => $size, 'gift' => false, 'store_id' => Fx::storeId('sa'), 'order_id' => strtolower((string) Str::ulid()), 'quantity' => 0, 'created_at' => now()];

        expect($insert(['quantity' => 0]))->toThrow(QueryException::class, 'hold_lines_quantity')
            ->and($insert(['shipped' => 3]))->toThrow(QueryException::class, 'hold_lines_shipped')
            ->and($insert(['shipped' => -1]))->toThrow(QueryException::class, 'hold_lines_shipped')
            ->and($insert(['ended' => 'LOST', 'ended_at' => now()]))->toThrow(QueryException::class, 'hold_lines_ended')
            ->and($insert(['ended_at' => now()]))->toThrow(QueryException::class, 'hold_lines_ended_at')
            ->and($insert(['ended' => 'RELEASED']))->toThrow(QueryException::class, 'hold_lines_ended_at')
            ->and(fn () => DB::transaction(fn () => DB::table('inventory.return_lines')->insert($return)))->toThrow(QueryException::class, 'return_lines_quantity');

        $insert([])();

        expect(DB::table('inventory.hold_lines')->count())->toBe(1);
    });

    it('never lets a store with stock go, takes a size\'s stock with it, and keeps the ledger of a size that went', function () {
        $rules = collect(DB::select(
            "SELECT conname, confdeltype FROM pg_constraint WHERE contype = 'f' AND connamespace = 'inventory'::regnamespace ORDER BY conname",
        ))->mapWithKeys(static fn (stdClass $key): array => [(string) $key->conname => (string) $key->confdeltype])->all();

        // The ledger names its size as the audit log names a subject: no key, so its history stays.
        expect($rules)->toBe([
            'hold_lines_hold' => 'c',
            'hold_lines_variant' => 'r',
            'holds_store' => 'r',
            'low_counts_store' => 'r',
            'return_lines_store' => 'r',
            'stock_movements_store' => 'r',
            'stock_product' => 'c',
            'stock_store' => 'r',
            'stock_variant' => 'c',
            'store_products_product' => 'c',
            'store_products_store' => 'r',
        ]);
    });
});
