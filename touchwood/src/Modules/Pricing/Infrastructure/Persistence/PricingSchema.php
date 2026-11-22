<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;

/**
 * The pricing schema itself (pricing.md §5), as the first migration creates and drops it - kept here
 * so a test can run both from nothing, which a migration file cannot be asked to do (as Catalog's
 * schema does). Pricing needs no extension.
 */
final class PricingSchema
{
    public static function create(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS pricing');
    }

    public static function drop(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS pricing CASCADE');
    }
}
