<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;

/**
 * The content schema itself (content.md §5), as the first migration creates and drops it - kept here, as
 * Ops' OpsSchema is, so the statements have one home that a test can run from nothing.
 *
 * The schema is on config/database.php's search_path (§9.4 A2), so a fresh migration wipes it with the rest.
 */
final class ContentSchema
{
    public static function create(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS content');
    }

    public static function drop(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS content CASCADE');
    }
}
