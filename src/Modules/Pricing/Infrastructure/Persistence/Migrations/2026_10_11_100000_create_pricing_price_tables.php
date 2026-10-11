<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| pricing.md §5, step 3a: each size's retail price in a store, and the materialized candidates every
| price is resolved from (§1.6) - rewritten per size by every change, so a cart's prices are one
| indexed read. Money is bigint minor units with its currency (handoff §19). A store is never deleted
| from under its prices; a variant that goes takes its prices with it.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing.retail_prices', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->ulid('variant_id');
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('source', 16);
            $table->timestampTz('updated_at');

            $table->primary(['store_id', 'variant_id'], 'retail_prices_pkey');
            $table->foreign('store_id', 'retail_prices_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('variant_id', 'retail_prices_variant')->references('id')->on('catalog.variants')->cascadeOnDelete();
            $table->index('variant_id', 'retail_prices_variant_idx');
        });

        // A price is always above 0 (§1.1 rule 4); where it came from (§5).
        DB::statement('ALTER TABLE pricing.retail_prices ADD CONSTRAINT retail_prices_positive CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE pricing.retail_prices ADD CONSTRAINT retail_prices_currency CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement("ALTER TABLE pricing.retail_prices ADD CONSTRAINT retail_prices_source CHECK (source IN ('STAFF', 'FILE', 'PROVIDER'))");

        Schema::create('pricing.price_candidates', function (Blueprint $table) {
            $table->id();
            $table->ulid('store_id');
            $table->ulid('variant_id');
            $table->string('mode', 16);
            $table->integer('from_quantity');
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('kind', 16);
            $table->boolean('always_wins');
            $table->ulid('source_id')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();

            $table->foreign('store_id', 'price_candidates_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('variant_id', 'price_candidates_variant')->references('id')->on('catalog.variants')->cascadeOnDelete();
            $table->index(['store_id', 'variant_id', 'mode'], 'price_candidates_line_idx');
            $table->index('variant_id', 'price_candidates_variant_idx');
        });

        // Written so a NULL cannot slip through (lesson 162): a window's end, when it has one, is after
        // its start; only a dated kind has a window; a band starts at 1 or more.
        DB::statement('ALTER TABLE pricing.price_candidates ADD CONSTRAINT price_candidates_positive CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE pricing.price_candidates ADD CONSTRAINT price_candidates_currency CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement("ALTER TABLE pricing.price_candidates ADD CONSTRAINT price_candidates_mode CHECK (mode IN ('RETAIL', 'WHOLESALE'))");
        DB::statement("ALTER TABLE pricing.price_candidates ADD CONSTRAINT price_candidates_kind CHECK (kind IN ('BASE', 'SALE', 'CAMPAIGN', 'CATEGORY', 'QUANTITY'))");
        DB::statement('ALTER TABLE pricing.price_candidates ADD CONSTRAINT price_candidates_from_quantity CHECK (from_quantity >= 1)');
        DB::statement('ALTER TABLE pricing.price_candidates ADD CONSTRAINT price_candidates_window CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)');
        DB::statement("ALTER TABLE pricing.price_candidates ADD CONSTRAINT price_candidates_undated CHECK (kind NOT IN ('BASE', 'QUANTITY') OR (starts_at IS NULL AND ends_at IS NULL AND always_wins = false))");
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing.price_candidates');
        Schema::dropIfExists('pricing.retail_prices');
    }
};
