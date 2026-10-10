<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| catalog.md §2.2, §5.2 (amendments 15, 16(h), 16(i)): the facts the listing needs and does not own,
| pushed in by the modules that own them through `ListingFacts` and kept here, in Catalog's own
| table, so a listing written again from Catalog's tables never loses them: whether a variant can be
| ordered now and whether it is ending soon (Inventory), and its price now and before (Pricing). A
| row per variant a store has a fact for; a variant with none follows §1.3's rule.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog.store_variant_facts', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->ulid('variant_id');
            // Inventory's: null until it pushes, when §1.3's rule answers.
            $table->boolean('orderable')->nullable();
            // Inventory's "last pieces" (amendment 16(h)).
            $table->boolean('ending_soon')->default(false);
            // Pricing's ListingPrice (amendment 15): the price now, and the base price only while now is lower.
            $table->bigInteger('price_minor')->nullable();
            $table->bigInteger('price_before_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestampsTz();

            $table->primary(['store_id', 'variant_id'], 'store_variant_facts_pkey');
            $table->foreign('store_id', 'store_variant_facts_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('variant_id', 'store_variant_facts_variant')->references('id')->on('catalog.variants')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE catalog.store_variant_facts ADD CONSTRAINT store_variant_facts_before_with_now CHECK (price_before_minor IS NULL OR (price_minor IS NOT NULL AND price_before_minor > price_minor))');
        DB::statement('ALTER TABLE catalog.store_variant_facts ADD CONSTRAINT store_variant_facts_currency_with_price CHECK ((price_minor IS NULL) = (currency IS NULL))');
        DB::statement('ALTER TABLE catalog.store_variant_facts ADD CONSTRAINT store_variant_facts_price_positive CHECK (price_minor IS NULL OR price_minor >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.store_variant_facts');
    }
};
