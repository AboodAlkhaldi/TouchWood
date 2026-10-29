<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Ops\Infrastructure\Persistence\OpsSchema;

/*
| The ops schema (ops.md §5): created by the module's first migration, and on the connection's search
| path so a fresh migration wipes it with the rest.
*/

uses(RefreshDatabase::class);

it('creates the ops schema, on the search path so a fresh migration wipes it', function () {
    expect(DB::table('information_schema.schemata')->where('schema_name', 'ops')->exists())->toBeTrue()
        ->and(explode(',', (string) config('database.connections.pgsql.search_path')))->toContain('ops');
});

// A test database keeps its schemas from one run to the next, so this starts from nothing and runs
// what the migration runs; PostgreSQL's DDL is transactional, so the test's transaction puts it back.
it('creates the schema from nothing, and its rollback drops it', function () {
    DB::statement('DROP SCHEMA ops CASCADE');
    expect(DB::table('information_schema.schemata')->where('schema_name', 'ops')->exists())->toBeFalse();

    OpsSchema::create();
    expect(DB::table('information_schema.schemata')->where('schema_name', 'ops')->exists())->toBeTrue();

    OpsSchema::drop();
    expect(DB::table('information_schema.schemata')->where('schema_name', 'ops')->exists())->toBeFalse();
});

it('runs the migration itself up and down', function () {
    $migration = require base_path('src/Modules/Ops/Infrastructure/Persistence/Migrations/2026_10_29_500000_create_ops_schema.php');
    $exists = static fn (): bool => DB::table('information_schema.schemata')->where('schema_name', 'ops')->exists();

    $migration->down();
    expect($exists())->toBeFalse();

    $migration->up();
    expect($exists())->toBeTrue();
});
