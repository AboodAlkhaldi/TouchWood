<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;

/**
 * The catalog schema itself (catalog.md §5), as the first migration creates and drops it — kept
 * here so a test can run both from nothing, which a migration file cannot be asked to do
 * (Access's address formats set the precedent, access.md amendment 41).
 *
 * pg_trgm serves the search's "nearest to what they typed" (handoff §9.5, catalog.md §1.11). It is
 * a trusted extension, so the database's owner may create it: the application's database user must
 * own the database, or a superuser creates it once beforehand. It is pinned to the public schema,
 * which every connection has on its path: without the pin it would land in the first schema of the
 * search path that exists, and a module's rollback could then drop it with every trigram index.
 */
final class CatalogSchema
{
    public static function create(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS catalog');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public');
    }

    /**
     * The extension stays: it belongs to the database, not to this schema.
     */
    public static function drop(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS catalog CASCADE');
    }
}
