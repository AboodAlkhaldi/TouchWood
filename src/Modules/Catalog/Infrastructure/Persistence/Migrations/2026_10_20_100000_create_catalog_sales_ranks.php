<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| catalog.md amendment 30 (sales.md §1.13): each store's best-selling ranks, pushed in by Sales each night
| (Catalog never reads Sales) and kept in a table of their own, so a listing row rewritten by any change
| takes its rank from here — the shop's Best-Selling sort reads the row's `sales_rank`.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog.sales_ranks', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->ulid('product_id');
            $table->integer('rank');
            $table->timestampTz('updated_at');

            $table->primary(['store_id', 'product_id'], 'catalog_sales_ranks_pkey');
            $table->foreign('store_id', 'catalog_sales_ranks_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('product_id', 'catalog_sales_ranks_product')->references('id')->on('catalog.products')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE catalog.sales_ranks ADD CONSTRAINT catalog_sales_ranks_rank CHECK (rank >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.sales_ranks');
    }
};
