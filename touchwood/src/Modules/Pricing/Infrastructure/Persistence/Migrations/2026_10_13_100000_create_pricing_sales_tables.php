<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| pricing.md §1.3, §5, step 4: a sale - a price or a percentage off, for a time - and the sizes it
| covers. Every sale is ours, in every store: the provider gives the base price only (owner,
| 2026-10-13), so a sale has no source. A product that goes takes its sales with it.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing.sales', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->ulid('product_id');
            $table->integer('percent_bp')->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('always_wins')->default(false);
            $table->timestampTz('created_at');

            $table->foreign('store_id', 'sales_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('product_id', 'sales_product')->references('id')->on('catalog.products')->cascadeOnDelete();
            $table->index(['store_id', 'ends_at'], 'sales_store_idx');
            $table->index('product_id', 'sales_product_idx');
        });

        // Exactly one of a percentage and a price, a price with its currency; NULL-safe (lesson 162).
        DB::statement(<<<'SQL'
            ALTER TABLE pricing.sales
                ADD CONSTRAINT sales_one_kind CHECK ((percent_bp IS NULL) <> (amount_minor IS NULL)),
                ADD CONSTRAINT sales_percent CHECK (percent_bp IS NULL OR percent_bp BETWEEN 1 AND 9999),
                ADD CONSTRAINT sales_amount CHECK (amount_minor IS NULL OR amount_minor > 0),
                ADD CONSTRAINT sales_currency CHECK ((amount_minor IS NULL) = (currency_code IS NULL) AND (currency_code IS NULL OR currency_code ~ '^[A-Z]{3}$')),
                ADD CONSTRAINT sales_window CHECK (ends_at IS NULL OR ends_at > starts_at)
            SQL);

        Schema::create('pricing.sale_variants', function (Blueprint $table) {
            $table->ulid('sale_id');
            $table->ulid('variant_id');

            $table->primary(['sale_id', 'variant_id'], 'sale_variants_pkey');
            $table->foreign('sale_id', 'sale_variants_sale')->references('id')->on('pricing.sales')->cascadeOnDelete();
            $table->foreign('variant_id', 'sale_variants_variant')->references('id')->on('catalog.variants')->cascadeOnDelete();
            $table->index('variant_id', 'sale_variants_variant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing.sale_variants');
        Schema::dropIfExists('pricing.sales');
    }
};
