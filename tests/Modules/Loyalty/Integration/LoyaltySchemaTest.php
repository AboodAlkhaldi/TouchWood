<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Infrastructure\Persistence\LoyaltySchema;

/*
| The loyalty schema (loyalty.md §5). The tables and their checks join this file with the step that
| creates them.
*/

uses(RefreshDatabase::class);

it('creates the loyalty schema, on the search path so a fresh migration wipes it', function () {
    expect(DB::table('information_schema.schemata')->where('schema_name', 'loyalty')->exists())->toBeTrue()
        ->and(explode(',', (string) config('database.connections.pgsql.search_path')))->toContain('loyalty');
});

// A test database keeps its schemas from one run to the next (migrate:fresh drops tables, never
// schemas), so the test above cannot tell whether the migration still creates it. This one starts
// from nothing and runs what the migration runs; PostgreSQL's DDL is transactional, so the test's own
// transaction puts everything back.
it('creates the schema from nothing, and its rollback drops it', function () {
    DB::statement('DROP SCHEMA loyalty CASCADE');

    expect(DB::table('information_schema.schemata')->where('schema_name', 'loyalty')->exists())->toBeFalse();

    LoyaltySchema::create();

    expect(DB::table('information_schema.schemata')->where('schema_name', 'loyalty')->exists())->toBeTrue();

    LoyaltySchema::drop();

    expect(DB::table('information_schema.schemata')->where('schema_name', 'loyalty')->exists())->toBeFalse();
});

it('runs the migration itself up and down: it creates the schema, and its rollback drops it', function () {
    $migration = require base_path('src/Modules/Loyalty/Infrastructure/Persistence/Migrations/2026_10_10_500000_create_loyalty_schema.php');
    $exists = static fn (): bool => DB::table('information_schema.schemata')->where('schema_name', 'loyalty')->exists();

    $migration->down();
    expect($exists())->toBeFalse();

    $migration->up();
    expect($exists())->toBeTrue();
});
