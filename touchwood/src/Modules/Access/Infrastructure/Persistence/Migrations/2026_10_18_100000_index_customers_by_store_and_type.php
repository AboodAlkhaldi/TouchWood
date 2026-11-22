<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Amendment 70: the customers registered in a store, of one account type, keyset by id — Promotions'
| segments read them in pages of a thousand. The index answers the read without scanning every
| customer; the anonymized are filtered from it.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX customers_by_store_type ON access.customers (home_store_id, account_type, id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS access.customers_by_store_type');
    }
};
