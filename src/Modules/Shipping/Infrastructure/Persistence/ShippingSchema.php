<?php

declare(strict_types=1);

namespace Modules\Shipping\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;

final class ShippingSchema
{
    public static function create(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS shipping');
    }

    public static function drop(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS shipping CASCADE');
    }
}
