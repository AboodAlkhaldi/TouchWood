<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| catalog.md §5.1 with amendment 3: what hangs off a product — its gallery and each variant's photos
| (Platform media, RESTRICT, detached through MediaUsage), its search words, its filter values and
| its hand-picked relations. Each goes with a deleted draft; whatever it points at is RESTRICT.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog.product_photos', function (Blueprint $table) {
            $table->ulid('product_id');
            $table->ulid('media_id');
            $table->integer('position');

            $table->primary(['product_id', 'media_id'], 'product_photos_pkey');
            $table->foreign('product_id', 'product_photos_product')->references('id')->on('catalog.products')->cascadeOnDelete();
            $table->foreign('media_id', 'product_photos_media')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index('media_id', 'product_photos_media_idx');
        });

        Schema::create('catalog.variant_photos', function (Blueprint $table) {
            $table->ulid('variant_id');
            $table->ulid('media_id');
            $table->integer('position');

            $table->primary(['variant_id', 'media_id'], 'variant_photos_pkey');
            $table->foreign('variant_id', 'variant_photos_variant')->references('id')->on('catalog.variants')->cascadeOnDelete();
            $table->foreign('media_id', 'variant_photos_media')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index('media_id', 'variant_photos_media_idx');
        });

        foreach (['product_photos', 'variant_photos'] as $table) {
            DB::statement("ALTER TABLE catalog.{$table} ADD CONSTRAINT {$table}_position_range CHECK (position BETWEEN 0 AND 10000)");
        }

        Schema::create('catalog.product_search_words', function (Blueprint $table) {
            $table->ulid('product_id');
            // As search reads it (handoff §5.2): one row per word, however it was typed (amendment 3(f)).
            $table->string('normalized', 50);
            $table->string('word', 50);
            $table->integer('position');

            $table->primary(['product_id', 'normalized'], 'product_search_words_pkey');
            $table->foreign('product_id', 'product_search_words_product')->references('id')->on('catalog.products')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE catalog.product_search_words ADD CONSTRAINT product_search_words_present CHECK (btrim(normalized) <> '' AND btrim(word) <> '' AND word !~ '[[:cntrl:]]')");

        Schema::create('catalog.product_filter_values', function (Blueprint $table) {
            $table->ulid('product_id');
            $table->ulid('attribute_id');
            $table->ulid('value_id');

            $table->primary(['product_id', 'value_id'], 'product_filter_values_pkey');
            $table->foreign('product_id', 'product_filter_values_product')->references('id')->on('catalog.products')->cascadeOnDelete();
            $table->foreign('attribute_id', 'product_filter_values_attribute')->references('id')->on('catalog.attributes')->restrictOnDelete();
            // A value of its own attribute (amendment 3(a)), as a variant's are.
            $table->foreign(['attribute_id', 'value_id'], 'product_filter_values_value')->references(['attribute_id', 'id'])->on('catalog.attribute_values')->restrictOnDelete();
            $table->index('value_id', 'product_filter_values_value_idx');
            $table->index('attribute_id', 'product_filter_values_attribute_idx');
        });

        Schema::create('catalog.product_relations', function (Blueprint $table) {
            $table->ulid('product_id');
            $table->ulid('related_id');
            $table->string('kind', 16);
            $table->integer('position');

            $table->primary(['product_id', 'related_id', 'kind'], 'product_relations_pkey');
            $table->foreign('product_id', 'product_relations_product')->references('id')->on('catalog.products')->cascadeOnDelete();
            $table->foreign('related_id', 'product_relations_related')->references('id')->on('catalog.products')->restrictOnDelete();
            $table->index('related_id', 'product_relations_related_idx');
        });

        DB::statement("ALTER TABLE catalog.product_relations ADD CONSTRAINT product_relations_kind CHECK (kind IN ('RELATED','GOES_WITH'))");
        DB::statement('ALTER TABLE catalog.product_relations ADD CONSTRAINT product_relations_not_itself CHECK (product_id <> related_id)');
        DB::statement('ALTER TABLE catalog.product_relations ADD CONSTRAINT product_relations_position_range CHECK (position BETWEEN 0 AND 10000)');
    }

    public function down(): void
    {
        foreach (['product_relations', 'product_filter_values', 'product_search_words', 'variant_photos', 'product_photos'] as $table) {
            Schema::dropIfExists("catalog.{$table}");
        }
    }
};
