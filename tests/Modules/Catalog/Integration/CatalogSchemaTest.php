<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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
