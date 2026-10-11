<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Infrastructure\Persistence\InventorySchema;

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
