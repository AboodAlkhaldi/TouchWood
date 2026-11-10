<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| pricing.md §9.6, stage 8: campaign prices - one set per campaign run (Content's run id), in one store,
| on retail lines, wholesale lines or both, from its start to its end (a campaign always has one); what it
| prices - products, sizes, categories and brands, each a percentage off, a size also a fixed price - and
| the products left out of its categories and brands; and the sizes a run skips now in a wired store
| because a provider's price would take it past the ceiling (§9.6 #5, read by `skippedSizes`).
|
| A store is never deleted from under a run. A target names its product, size, category or brand by id
| with no key: what it reaches is read when prices are written, and a category or brand in use is kept by
| Content's `CatalogUsage`. A product or size that goes takes its left-out row and its skip with it.
| Every CHECK on a nullable column is written so a NULL cannot slip through (lesson 162).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing.campaign_sets', function (Blueprint $table) {
            $table->ulid('run_id')->primary();
            $table->ulid('store_id');
            $table->string('lines', 16);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->foreign('store_id', 'campaign_sets_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->index(['store_id', 'ends_at'], 'campaign_sets_store_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE pricing.campaign_sets
                ADD CONSTRAINT campaign_sets_lines CHECK (lines IN ('RETAIL', 'WHOLESALE', 'BOTH')),
                ADD CONSTRAINT campaign_sets_window CHECK (ends_at > starts_at)
            SQL);

        Schema::create('pricing.campaign_targets', function (Blueprint $table) {
            $table->ulid('run_id');
            $table->string('kind', 16);
            $table->ulid('target_id');
            $table->integer('percent_bp')->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency_code', 3)->nullable();

            $table->primary(['run_id', 'kind', 'target_id'], 'campaign_targets_pkey');
            $table->foreign('run_id', 'campaign_targets_run')->references('run_id')->on('pricing.campaign_sets')->cascadeOnDelete();
        });

        // Exactly one of a percentage and a price, a price only on a size and with its currency; NULL-safe.
        DB::statement(<<<'SQL'
            ALTER TABLE pricing.campaign_targets
                ADD CONSTRAINT campaign_targets_kind CHECK (kind IN ('PRODUCT', 'SIZE', 'CATEGORY', 'BRAND')),
                ADD CONSTRAINT campaign_targets_one_kind CHECK ((percent_bp IS NULL) <> (amount_minor IS NULL)),
                ADD CONSTRAINT campaign_targets_percent CHECK (percent_bp IS NULL OR percent_bp BETWEEN 1 AND 9999),
                ADD CONSTRAINT campaign_targets_amount CHECK (amount_minor IS NULL OR (amount_minor > 0 AND kind = 'SIZE')),
                ADD CONSTRAINT campaign_targets_currency CHECK ((amount_minor IS NULL) = (currency_code IS NULL) AND (currency_code IS NULL OR currency_code ~ '^[A-Z]{3}$'))
            SQL);

        Schema::create('pricing.campaign_left_out', function (Blueprint $table) {
            $table->ulid('run_id');
            $table->ulid('product_id');

            $table->primary(['run_id', 'product_id'], 'campaign_left_out_pkey');
            $table->foreign('run_id', 'campaign_left_out_run')->references('run_id')->on('pricing.campaign_sets')->cascadeOnDelete();
            $table->foreign('product_id', 'campaign_left_out_product')->references('id')->on('catalog.products')->cascadeOnDelete();
            $table->index('product_id', 'campaign_left_out_product_idx');
        });

        Schema::create('pricing.campaign_skips', function (Blueprint $table) {
            $table->ulid('run_id');
            $table->ulid('variant_id');
            $table->ulid('store_id');

            $table->primary(['run_id', 'variant_id'], 'campaign_skips_pkey');
            $table->foreign('run_id', 'campaign_skips_run')->references('run_id')->on('pricing.campaign_sets')->cascadeOnDelete();
            $table->foreign('variant_id', 'campaign_skips_variant')->references('id')->on('catalog.variants')->cascadeOnDelete();
            $table->index(['store_id', 'variant_id'], 'campaign_skips_store_idx');
        });

        // A campaign's candidate names its run, as a sale's and a discount's name theirs.
        DB::statement('ALTER TABLE pricing.price_candidates DROP CONSTRAINT price_candidates_source');
        DB::statement("ALTER TABLE pricing.price_candidates ADD CONSTRAINT price_candidates_source CHECK (kind NOT IN ('SALE', 'CATEGORY', 'CAMPAIGN') OR source_id IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM pricing.price_candidates WHERE kind = 'CAMPAIGN'");
        DB::statement('ALTER TABLE pricing.price_candidates DROP CONSTRAINT price_candidates_source');
        DB::statement("ALTER TABLE pricing.price_candidates ADD CONSTRAINT price_candidates_source CHECK (kind NOT IN ('SALE', 'CATEGORY') OR source_id IS NOT NULL)");
        Schema::dropIfExists('pricing.campaign_skips');
        Schema::dropIfExists('pricing.campaign_left_out');
        Schema::dropIfExists('pricing.campaign_targets');
        Schema::dropIfExists('pricing.campaign_sets');
    }
};
