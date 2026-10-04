<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| catalog.md §5.1 with amendment 4: each store's choice — a product's row in a store (its "Not
| available now" and its quantity limits), each variant's row (switched on or off, "Not available
| now", its selling modes) and the labels the store shows on it. A variant's row needs its product's
| row in the same store, and the variant is that product's; a store is never deleted from under them.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog.store_products', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->ulid('product_id');
            $table->boolean('not_available_now')->default(false);
            $table->integer('retail_minimum')->default(1);
            $table->integer('retail_maximum')->nullable();
            $table->integer('wholesale_minimum')->nullable();
            $table->integer('wholesale_maximum')->nullable();
            $table->timestampsTz();

            $table->primary(['store_id', 'product_id'], 'store_products_pkey');
            $table->foreign('store_id', 'store_products_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('product_id', 'store_products_product')->references('id')->on('catalog.products')->cascadeOnDelete();
            $table->index('product_id', 'store_products_product_idx');
        });

        // Each limit 1–100,000, a maximum never below its minimum (§1.3, §9.5 #1).
        foreach (['retail_minimum', 'retail_maximum', 'wholesale_minimum', 'wholesale_maximum'] as $column) {
            DB::statement("ALTER TABLE catalog.store_products ADD CONSTRAINT store_products_{$column}_range CHECK ({$column} IS NULL OR {$column} BETWEEN 1 AND 100000)");
        }

        DB::statement('ALTER TABLE catalog.store_products ADD CONSTRAINT store_products_retail_order CHECK (retail_maximum IS NULL OR retail_maximum >= retail_minimum)');
        DB::statement('ALTER TABLE catalog.store_products ADD CONSTRAINT store_products_wholesale_order CHECK (wholesale_maximum IS NULL OR (wholesale_minimum IS NOT NULL AND wholesale_maximum >= wholesale_minimum))');

        // A variant's row points at its product's: the variant is that product's.
        DB::statement('ALTER TABLE catalog.variants ADD CONSTRAINT variants_product_and_id UNIQUE (product_id, id)');

        Schema::create('catalog.store_variants', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->ulid('variant_id');
            $table->ulid('product_id');
            $table->boolean('is_active');
            $table->boolean('not_available_now')->default(false);
            $table->boolean('sells_retail');
            $table->boolean('sells_wholesale');
            $table->timestampsTz();

            $table->primary(['store_id', 'variant_id'], 'store_variants_pkey');
            $table->foreign(['store_id', 'product_id'], 'store_variants_store_product')->references(['store_id', 'product_id'])->on('catalog.store_products')->cascadeOnDelete();
            $table->foreign(['product_id', 'variant_id'], 'store_variants_variant')->references(['product_id', 'id'])->on('catalog.variants')->cascadeOnDelete();
            $table->index(['product_id', 'is_active'], 'store_variants_product_idx');
            $table->index('variant_id', 'store_variants_variant_idx');
        });

        // At least one selling mode (§1.3).
        DB::statement('ALTER TABLE catalog.store_variants ADD CONSTRAINT store_variants_one_mode CHECK (sells_retail OR sells_wholesale)');

        Schema::create('catalog.store_product_labels', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->ulid('product_id');
            $table->ulid('label_id');

            $table->primary(['store_id', 'product_id', 'label_id'], 'store_product_labels_pkey');
            $table->foreign(['store_id', 'product_id'], 'store_product_labels_store_product')->references(['store_id', 'product_id'])->on('catalog.store_products')->cascadeOnDelete();
            $table->foreign('label_id', 'store_product_labels_label')->references('id')->on('catalog.labels')->restrictOnDelete();
            $table->index('label_id', 'store_product_labels_label_idx');
        });
    }

    public function down(): void
    {
        foreach (['store_product_labels', 'store_variants', 'store_products'] as $table) {
            Schema::dropIfExists("catalog.{$table}");
        }

        DB::statement('ALTER TABLE catalog.variants DROP CONSTRAINT IF EXISTS variants_product_and_id');
    }
};
