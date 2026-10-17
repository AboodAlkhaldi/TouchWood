<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;

/**
 * The sync schema itself (sync.md §5), as the first migration creates and drops it - kept here so a
 * test can run both from nothing, which a migration file cannot be asked to do (as Inventory's
 * schema does). Sync needs no extension.
 */
final class SyncSchema
{
    public static function create(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS sync');
    }

    public static function drop(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS sync CASCADE');
    }
}
