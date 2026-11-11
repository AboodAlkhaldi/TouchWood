<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| catalog.md §5.5 (amendment 43; owner, 2026-11-11): **a store file follows its store's wiring** - a store
| wired to Odoo gives codes only, its prices and stock being Odoo's, so its items have no price; a store
| not wired still gives each item its price. `price` may therefore be empty; which stores must give one
| is decided when the file is read (the store's wiring is Sync's, not a column here).
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE catalog.store_fill_items ALTER COLUMN price DROP NOT NULL');
    }

    public function down(): void
    {
        // A wired store's items have no price: they come back as 0, as text, the way the column keeps it.
        DB::statement("UPDATE catalog.store_fill_items SET price = '0' WHERE price IS NULL");
        DB::statement('ALTER TABLE catalog.store_fill_items ALTER COLUMN price SET NOT NULL');
    }
};
