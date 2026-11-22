<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;

final class PaymentsSchema
{
    public static function create(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS payments');
    }

    public static function drop(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS payments CASCADE');
    }
}
