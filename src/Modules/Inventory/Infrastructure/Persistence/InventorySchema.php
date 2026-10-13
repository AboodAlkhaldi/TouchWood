<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;

/**
 * The inventory schema itself (inventory.md §5), as the first migration creates and drops it - kept
 * here so a test can run both from nothing, which a migration file cannot be asked to do (as
 * Catalog's schema does). Inventory needs no extension.
 */
final class InventorySchema
{
    public static function create(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS inventory');
    }

    public static function drop(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS inventory CASCADE');
    }
}
