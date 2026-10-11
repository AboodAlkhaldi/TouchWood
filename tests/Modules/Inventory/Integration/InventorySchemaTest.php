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
            ->and($insert(['actor_type' => 'ROBOT']))->toThrow(QueryException::class, 'stock_movements_actor_type');

        $insert([])();
        $insert(['reason' => 'CORRECTION', 'note' => 'Found two more'])();

        expect(DB::table('inventory.stock_movements')->count())->toBe(2);
    });

    it('never lets a store with stock go, takes a size\'s stock with it, and keeps the ledger of a size that went', function () {
        $rules = collect(DB::select(
            "SELECT conname, confdeltype FROM pg_constraint WHERE contype = 'f' AND connamespace = 'inventory'::regnamespace ORDER BY conname",
        ))->mapWithKeys(static fn (stdClass $key): array => [(string) $key->conname => (string) $key->confdeltype])->all();

        // The ledger names its size as the audit log names a subject: no key, so its history stays.
        expect($rules)->toBe([
            'stock_movements_store' => 'r',
            'stock_product' => 'c',
            'stock_store' => 'r',
            'stock_variant' => 'c',
            'store_products_product' => 'c',
            'store_products_store' => 'r',
        ]);
    });
});
