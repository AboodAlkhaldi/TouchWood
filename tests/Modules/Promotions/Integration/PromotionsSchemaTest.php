<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Promotions\Infrastructure\Persistence\PromotionsSchema;

/*
| The promotions schema (promotions.md §5). The tables and their checks join this file with the step
| that creates them.
*/

uses(RefreshDatabase::class);

it('creates the promotions schema, on the search path so a fresh migration wipes it', function () {
    expect(DB::table('information_schema.schemata')->where('schema_name', 'promotions')->exists())->toBeTrue()
        ->and(explode(',', (string) config('database.connections.pgsql.search_path')))->toContain('promotions');
});

// A test database keeps its schemas from one run to the next (migrate:fresh drops tables, never
// schemas), so the test above cannot tell whether the migration still creates it. This one starts
// from nothing and runs what the migration runs; PostgreSQL's DDL is transactional, so the test's own
// transaction puts everything back.
it('creates the schema from nothing, and its rollback drops it', function () {
    DB::statement('DROP SCHEMA promotions CASCADE');

    expect(DB::table('information_schema.schemata')->where('schema_name', 'promotions')->exists())->toBeFalse();

    PromotionsSchema::create();

    expect(DB::table('information_schema.schemata')->where('schema_name', 'promotions')->exists())->toBeTrue();

    PromotionsSchema::drop();

    expect(DB::table('information_schema.schemata')->where('schema_name', 'promotions')->exists())->toBeFalse();
});

it('runs the migration itself up and down: it creates the schema, and its rollback drops it', function () {
    $migration = require base_path('src/Modules/Promotions/Infrastructure/Persistence/Migrations/2026_10_17_100000_create_promotions_schema.php');
    $exists = static fn (): bool => DB::table('information_schema.schemata')->where('schema_name', 'promotions')->exists();

    $migration->down();
    expect($exists())->toBeFalse();

    $migration->up();
    expect($exists())->toBeTrue();
});
