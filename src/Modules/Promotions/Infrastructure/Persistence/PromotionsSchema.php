<?php

declare(strict_types=1);

namespace Modules\Promotions\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;

/**
 * The promotions schema itself (promotions.md §5), as the first migration creates and drops it — kept
 * here, as Loyalty's LoyaltySchema is, so the statements have one home that a test can run from nothing.
 *
 * The schema is on config/database.php's search_path, so a fresh migration wipes it with the rest.
 */
final class PromotionsSchema
{
    public static function create(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS promotions');
    }

    public static function drop(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS promotions CASCADE');
    }
}
