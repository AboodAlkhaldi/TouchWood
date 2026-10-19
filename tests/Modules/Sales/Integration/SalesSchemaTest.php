<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Infrastructure\Persistence\SalesSchema;

/*
| The sales schema (sales.md §5): created by the module's first migration, and on the
| connection's search path so a fresh migration wipes it with the rest.
*/

uses(RefreshDatabase::class);

it('creates the sales schema, on the search path so a fresh migration wipes it', function () {
    expect(DB::table('information_schema.schemata')->where('schema_name', 'sales')->exists())->toBeTrue()
        ->and(explode(',', (string) config('database.connections.pgsql.search_path')))->toContain('sales');
});

// A test database keeps its schemas from one run to the next, so this starts from nothing and runs
// what the migration runs; PostgreSQL's DDL is transactional, so the test's transaction puts it back.
it('creates the schema from nothing, and its rollback drops it', function () {
    DB::statement('DROP SCHEMA sales CASCADE');
    expect(DB::table('information_schema.schemata')->where('schema_name', 'sales')->exists())->toBeFalse();

    SalesSchema::create();
    expect(DB::table('information_schema.schemata')->where('schema_name', 'sales')->exists())->toBeTrue();

    SalesSchema::drop();
    expect(DB::table('information_schema.schemata')->where('schema_name', 'sales')->exists())->toBeFalse();
});

it('runs the migration itself up and down', function () {
    $migration = require base_path('src/Modules/Sales/Infrastructure/Persistence/Migrations/2026_10_20_200000_create_sales_schema.php');
    $exists = static fn (): bool => DB::table('information_schema.schemata')->where('schema_name', 'sales')->exists();

    $migration->down();
    expect($exists())->toBeFalse();

    $migration->up();
    expect($exists())->toBeTrue();
});
