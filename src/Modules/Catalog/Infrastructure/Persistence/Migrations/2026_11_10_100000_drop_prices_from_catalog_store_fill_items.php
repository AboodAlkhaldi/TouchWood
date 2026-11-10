<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| catalog.md §5.5 (amendment 43; owner, 2026-11-10): **a store file carries codes only** - a store's
| prices and stock come from Odoo - so its items keep no `price` and no `stock` any more. What the
| columns held was only ever shown on the file's page; nothing else reads them.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE catalog.store_fill_items DROP CONSTRAINT IF EXISTS store_fill_items_stock');
        DB::statement('ALTER TABLE catalog.store_fill_items DROP COLUMN IF EXISTS stock');
        DB::statement('ALTER TABLE catalog.store_fill_items DROP COLUMN IF EXISTS price');
    }

    public function down(): void
    {
        // The rows' prices are gone: they come back as 0, as text, the way the column kept them.
        DB::statement("ALTER TABLE catalog.store_fill_items ADD COLUMN price varchar(32) NOT NULL DEFAULT '0'");
        DB::statement('ALTER TABLE catalog.store_fill_items ALTER COLUMN price DROP DEFAULT');
        DB::statement('ALTER TABLE catalog.store_fill_items ADD COLUMN stock integer NULL');
        DB::statement('ALTER TABLE catalog.store_fill_items ADD CONSTRAINT store_fill_items_stock CHECK (stock IS NULL OR stock >= 0)');
    }
};
