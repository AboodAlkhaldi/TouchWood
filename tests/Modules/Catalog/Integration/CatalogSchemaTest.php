<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Infrastructure\Persistence\CatalogSchema;

/*
| The catalog schema and what the search needs from the database (catalog.md §5, §1.11). The tables
| and their checks join this file with the steps that create them.
*/

uses(RefreshDatabase::class);

it('creates the catalog schema, on the search path so a fresh migration wipes it', function () {
    expect(DB::table('information_schema.schemata')->where('schema_name', 'catalog')->exists())->toBeTrue()
        ->and(explode(',', (string) config('database.connections.pgsql.search_path')))->toContain('catalog');
});

it('installs pg_trgm, so the search can rank by nearness to what was typed', function () {
    expect(DB::table('pg_extension')->where('extname', 'pg_trgm')->exists())->toBeTrue();

    // Not only installed: reachable from the connection's search path without naming a schema.
    $row = DB::selectOne("select similarity('hinge', 'hinge') as same, similarity('hinge', 'xyz') as different");

    expect((float) $row?->same)->toBe(1.0)
        ->and((float) $row?->different)->toBeLessThan(1.0);
});

// A test database keeps its schema and its extension from one run to the next (migrate:fresh drops
// tables, never schemas or extensions), so the two tests above cannot tell whether the migration
// still creates them. This one starts from nothing and runs what the migration runs; PostgreSQL's
// DDL is transactional, so the test's own transaction puts everything back (review of step 1).
it('creates the schema and the extension from nothing, and its rollback drops only the schema', function () {
    DB::statement('DROP SCHEMA catalog CASCADE');
    DB::statement('DROP EXTENSION pg_trgm');

    CatalogSchema::create();

    expect(DB::table('information_schema.schemata')->where('schema_name', 'catalog')->exists())->toBeTrue()
        ->and(DB::table('pg_extension')->where('extname', 'pg_trgm')->exists())->toBeTrue()
        // Pinned to public, so no module's rollback can ever take it (the migration's own comment).
        ->and(DB::selectOne('select n.nspname as schema from pg_extension e join pg_namespace n on n.oid = e.extnamespace where e.extname = ?', ['pg_trgm'])?->schema)->toBe('public');

    CatalogSchema::drop();

    expect(DB::table('information_schema.schemata')->where('schema_name', 'catalog')->exists())->toBeFalse()
        ->and(DB::table('pg_extension')->where('extname', 'pg_trgm')->exists())->toBeTrue();
});
