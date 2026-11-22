<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| catalog.md amendment 25 (feedback.md §1.2): each product's rating — one for every store, as reviews
| are global — and whether each store's lists show ratings, both pushed in by Feedback (Catalog never
| reads Feedback). Read when a list is built: stars on cards and the weighted rating sort. A store
| with no row shows ratings, as the setting's default.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog.product_ratings', function (Blueprint $table) {
            $table->ulid('product_id');
            $table->bigInteger('rating_sum');
            $table->integer('rating_count');
            $table->timestampTz('updated_at');

            $table->primary('product_id', 'catalog_product_ratings_pkey');
            $table->foreign('product_id', 'catalog_product_ratings_product')->references('id')->on('catalog.products')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE catalog.product_ratings ADD CONSTRAINT catalog_product_ratings_sum CHECK (rating_count >= 0 AND rating_sum BETWEEN rating_count AND 5 * rating_count)');

        Schema::create('catalog.store_rating_display', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->boolean('shown');
            $table->timestampTz('updated_at');

            $table->primary('store_id', 'store_rating_display_pkey');
            $table->foreign('store_id', 'store_rating_display_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.store_rating_display');
        Schema::dropIfExists('catalog.product_ratings');
    }
};
