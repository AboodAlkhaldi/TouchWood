<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| The store's accounts, found by their store (loyalty.md §4.1 L1, §5; the review of 2026-10-19): the
| holders list reads every account of one store, and neither of the table's indexes starts with the
| store, so without this each page would read the whole table.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX accounts_by_store ON loyalty.accounts (store_id, id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX loyalty.accounts_by_store');
    }
};
