<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| An order line keeps its product's unit of sale as it was when ordered (shop-design.md §10.7, P25): a later change to
| the product never rewrites an order. The lines placed before it were sold by the piece, as every product was then.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE sales.order_lines ADD COLUMN sale_unit varchar(12) NOT NULL DEFAULT 'PIECE'");
        DB::statement("ALTER TABLE sales.order_lines ADD CONSTRAINT order_lines_sale_unit CHECK (sale_unit IN ('PIECE', 'SET', 'PAIR', 'METRE', 'CARTON', 'BOX'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales.order_lines DROP COLUMN sale_unit');
    }
};
