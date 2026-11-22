<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A store's boxes and the sizes it packs by hand (shipping.md §1.7, §5). Box names are unique in the store
| ignoring case; the handler trims them. A size packed by hand is a row; every other size is packed by the
| suggestion - Catalog's size, no key (Shipping reads Catalog through its contract only).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping.boxes', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->string('name', 100);
            $table->integer('length_mm');
            $table->integer('width_mm');
            $table->integer('height_mm');
            $table->integer('max_weight_g');
            $table->boolean('active');
            $table->integer('position');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'boxes_pkey');
            $table->foreign('store_id', 'boxes_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE shipping.boxes
                ADD CONSTRAINT boxes_name CHECK (char_length(name) BETWEEN 1 AND 100 AND name = btrim(name)),
                ADD CONSTRAINT boxes_sizes CHECK (length_mm BETWEEN 1 AND 1000000 AND width_mm BETWEEN 1 AND 1000000
                    AND height_mm BETWEEN 1 AND 1000000 AND max_weight_g BETWEEN 1 AND 1000000),
                ADD CONSTRAINT boxes_position CHECK (position >= 1)
            SQL);
        DB::statement('CREATE UNIQUE INDEX boxes_name_unique ON shipping.boxes (store_id, lower(name))');
        DB::statement('CREATE INDEX boxes_by_store ON shipping.boxes (store_id, position, id)');

        Schema::create('shipping.variant_packing', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->ulid('variant_id');
            $table->timestampTz('created_at');

            $table->primary(['store_id', 'variant_id'], 'variant_packing_pkey');
            $table->foreign('store_id', 'variant_packing_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping.variant_packing');
        Schema::dropIfExists('shipping.boxes');
    }
};
