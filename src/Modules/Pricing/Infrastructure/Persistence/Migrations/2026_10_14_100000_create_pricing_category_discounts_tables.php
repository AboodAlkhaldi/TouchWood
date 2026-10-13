<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| pricing.md §5, step 6: category discounts - a percentage or a fixed amount off every product in a
| category and below it, in one store, on retail lines, wholesale lines or both, for a time - and the
| products each leaves out by name. A store or a category is never deleted from under a discount; a
| product that goes leaves its exclusions with it.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing.category_discounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->ulid('category_id');
            $table->integer('percent_bp')->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->string('lines', 16);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('always_wins')->default(false);
            $table->timestampTz('created_at');

            $table->foreign('store_id', 'category_discounts_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('category_id', 'category_discounts_category')->references('id')->on('catalog.categories')->restrictOnDelete();
            $table->index(['store_id', 'ends_at'], 'category_discounts_store_idx');
            $table->index('category_id', 'category_discounts_category_idx');
        });

        // Exactly one of a percentage and an amount, an amount with its currency; NULL-safe (lesson 162).
        DB::statement(<<<'SQL'
            ALTER TABLE pricing.category_discounts
                ADD CONSTRAINT category_discounts_one_kind CHECK ((percent_bp IS NULL) <> (amount_minor IS NULL)),
                ADD CONSTRAINT category_discounts_percent CHECK (percent_bp IS NULL OR percent_bp BETWEEN 1 AND 9999),
                ADD CONSTRAINT category_discounts_amount CHECK (amount_minor IS NULL OR amount_minor > 0),
                ADD CONSTRAINT category_discounts_currency CHECK ((amount_minor IS NULL) = (currency_code IS NULL) AND (currency_code IS NULL OR currency_code ~ '^[A-Z]{3}$')),
                ADD CONSTRAINT category_discounts_lines CHECK (lines IN ('RETAIL', 'WHOLESALE', 'BOTH')),
                ADD CONSTRAINT category_discounts_window CHECK (ends_at IS NULL OR ends_at > starts_at)
            SQL);

        Schema::create('pricing.category_discount_exclusions', function (Blueprint $table) {
            $table->ulid('discount_id');
            $table->ulid('product_id');

            $table->primary(['discount_id', 'product_id'], 'category_discount_exclusions_pkey');
            $table->foreign('discount_id', 'category_discount_exclusions_discount')->references('id')->on('pricing.category_discounts')->cascadeOnDelete();
            $table->foreign('product_id', 'category_discount_exclusions_product')->references('id')->on('catalog.products')->cascadeOnDelete();
            $table->index('product_id', 'category_discount_exclusions_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing.category_discount_exclusions');
        Schema::dropIfExists('pricing.category_discounts');
    }
};
