<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.6, §5: a banner - placement, the staff's name for it, a listing banner's category (none: every
| list), a link, whether the artwork has words, the description per language, its dates and its place in the
| order. Its state is read from `is_scheduled`, the dates and the clock, never stored (§4.1).
|
| `category_id` is RESTRICT: Content's CatalogUsage refuses Catalog's delete first (§1.1 rule 17). Scheduled
| means a start and both descriptions (§1.1 rule 2), which ScheduleBanner refuses first.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.banners', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->string('placement', 16);
            $table->ulid('category_id')->nullable();
            $table->string('name', 100);
            $table->text('link')->nullable();
            $table->boolean('has_words')->default(false);
            $table->string('alt_ar', 200)->nullable();
            $table->string('alt_en', 200)->nullable();
            $table->boolean('is_scheduled')->default(false);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->integer('position');
            $table->integer('version')->default(1);
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'banners_pkey');
            $table->foreign('store_id', 'banners_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('category_id', 'banners_category')->references('id')->on('catalog.categories')->restrictOnDelete();
            $table->index(['store_id', 'placement', 'position'], 'banners_order_idx');
            $table->index('category_id', 'banners_category_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.banners
                ADD CONSTRAINT banners_placement CHECK (placement IN ('HOME_HERO', 'HOME_PHONE', 'LISTING')),
                ADD CONSTRAINT banners_cat_place CHECK (category_id IS NULL OR placement = 'LISTING'),
                ADD CONSTRAINT banners_name CHECK (char_length(btrim(name)) >= 1),
                ADD CONSTRAINT banners_scheduled CHECK (NOT is_scheduled OR (starts_at IS NOT NULL AND alt_ar IS NOT NULL AND alt_en IS NOT NULL)),
                ADD CONSTRAINT banners_dates CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at),
                ADD CONSTRAINT banners_position CHECK (position >= 0),
                ADD CONSTRAINT banners_version CHECK (version >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.banners');
    }
};
