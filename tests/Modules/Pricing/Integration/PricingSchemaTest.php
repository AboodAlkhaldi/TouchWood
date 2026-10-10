<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Pricing\Infrastructure\Persistence\PricingSchema;

/*
| The pricing schema (pricing.md §5). The tables and their checks join this file with the steps that
| create them.
*/

uses(RefreshDatabase::class);

it('creates the pricing schema, on the search path so a fresh migration wipes it', function () {
    expect(DB::table('information_schema.schemata')->where('schema_name', 'pricing')->exists())->toBeTrue()
        ->and(explode(',', (string) config('database.connections.pgsql.search_path')))->toContain('pricing');
});

// A test database keeps its schemas from one run to the next (migrate:fresh drops tables, never
// schemas), so the test above cannot tell whether the migration still creates it. This one starts
// from nothing and runs what the migration runs; PostgreSQL's DDL is transactional, so the test's own
// transaction puts everything back (as Catalog's schema test).
it('creates the schema from nothing, and its rollback drops it', function () {
    DB::statement('DROP SCHEMA pricing CASCADE');

    PricingSchema::create();

    expect(DB::table('information_schema.schemata')->where('schema_name', 'pricing')->exists())->toBeTrue();

    PricingSchema::drop();

    expect(DB::table('information_schema.schemata')->where('schema_name', 'pricing')->exists())->toBeFalse();
});
