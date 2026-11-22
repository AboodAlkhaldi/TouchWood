<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| sales.md §1.5: Inventory's hold fixes at placement whether the store was wired and which lines count on
| its stock; ticking "reduced in the provider" and a parcel that came back go by the same facts, not by how
| the store is set today — so they are kept on the order as it is placed.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sales.orders ADD COLUMN wired boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE sales.order_lines ADD COLUMN counts_on_stock boolean NOT NULL DEFAULT true');
        // The gift always counts on stock (handoff §11.6).
        DB::statement('ALTER TABLE sales.order_lines ADD CONSTRAINT order_lines_gift_counts CHECK (NOT is_gift OR counts_on_stock)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales.order_lines DROP CONSTRAINT IF EXISTS order_lines_gift_counts');
        DB::statement('ALTER TABLE sales.order_lines DROP COLUMN IF EXISTS counts_on_stock');
        DB::statement('ALTER TABLE sales.orders DROP COLUMN IF EXISTS wired');
    }
};
