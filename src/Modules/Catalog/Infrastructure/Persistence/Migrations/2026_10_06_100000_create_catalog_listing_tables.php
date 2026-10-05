<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| catalog.md §5.4: the listing — one row per store, language and product a shopper can find there,
| written inside the change that alters it (§9.3 #20) — and the search log, which keeps no person.
| Codes are in neither (amendment 5(d)).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog.listing', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->char('locale', 2);
            $table->ulid('product_id');
            $table->string('name', 200);
            $table->string('slug', 200);
            $table->ulid('brand_id');
            $table->boolean('brand_visible_by_default');
            $table->ulid('category_id');
            $table->boolean('in_category_pages');
            $table->ulid('card_media_id')->nullable();
            $table->jsonb('card_photo')->nullable();
            $table->boolean('orderable');
            $table->bigInteger('price_minor')->nullable();
            $table->integer('sales_rank')->nullable();
            $table->text('search_text');

            $table->primary(['store_id', 'locale', 'product_id'], 'listing_pkey');
            $table->foreign('store_id', 'listing_store')->references('id')->on('platform.stores')->restrictOnDelete();
            // Only a draft is ever deleted, and a draft has no row; a row never holds one back.
            $table->foreign('product_id', 'listing_product')->references('id')->on('catalog.products')->cascadeOnDelete();
            $table->foreign('card_media_id', 'listing_card_media')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index('product_id', 'listing_product_idx');
            $table->index('card_media_id', 'listing_card_media_idx');
            $table->index(['store_id', 'locale', 'brand_visible_by_default', 'sales_rank'], 'listing_default_grid_idx');
            $table->index(['store_id', 'locale', 'brand_id'], 'listing_brand_idx');
        });

        // The ids above the category and the category itself, root first: a parent's page is one
        // indexed containment test (§1.5). Filter values and labels as arrays, for the same reason.
        DB::statement('ALTER TABLE catalog.listing ADD COLUMN category_path text[] NOT NULL');
        DB::statement('ALTER TABLE catalog.listing ADD COLUMN value_ids text[] NOT NULL');
        DB::statement('ALTER TABLE catalog.listing ADD COLUMN label_ids text[] NOT NULL');
        DB::statement('ALTER TABLE catalog.listing ADD COLUMN search_document tsvector NOT NULL');
        DB::statement("ALTER TABLE catalog.listing ADD CONSTRAINT listing_locale CHECK (locale IN ('ar','en'))");
        DB::statement('ALTER TABLE catalog.listing ADD CONSTRAINT listing_card_photo_with_media CHECK ((card_media_id IS NULL) = (card_photo IS NULL))');
        DB::statement('CREATE INDEX listing_category_path_idx ON catalog.listing USING gin (category_path)');
        DB::statement('CREATE INDEX listing_value_ids_idx ON catalog.listing USING gin (value_ids)');
        DB::statement('CREATE INDEX listing_search_document_idx ON catalog.listing USING gin (search_document)');
        DB::statement('CREATE INDEX listing_search_text_idx ON catalog.listing USING gin (search_text gin_trgm_ops)');

        Schema::create('catalog.search_log', function (Blueprint $table) {
            // bigint identity (§5.4), not bigserial.
            $table->id()->generatedAs()->always();
            $table->ulid('store_id');
            $table->char('locale', 2);
            $table->string('query', 200);
            $table->integer('results');

            $table->foreign('store_id', 'search_log_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE catalog.search_log ADD COLUMN searched_at timestamptz NOT NULL DEFAULT now()');
        DB::statement("ALTER TABLE catalog.search_log ADD CONSTRAINT search_log_locale CHECK (locale IN ('ar','en'))");
        DB::statement("ALTER TABLE catalog.search_log ADD CONSTRAINT search_log_query_present CHECK (btrim(query) <> '')");
        DB::statement('ALTER TABLE catalog.search_log ADD CONSTRAINT search_log_results_range CHECK (results >= 0)');
        DB::statement('CREATE INDEX search_log_searched_at_idx ON catalog.search_log (searched_at)');
        DB::statement('CREATE INDEX search_log_zero_results_idx ON catalog.search_log (store_id, results, searched_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.search_log');
        Schema::dropIfExists('catalog.listing');
    }
};
