<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| pricing.md §5, step 5: a size's wholesale prices in a store, in quantity bands (§1.4) - each band
| from a quantity, at a price above 0. A store is never deleted from under them; a variant that goes
| takes its bands with it.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing.wholesale_bands', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->ulid('variant_id');
            $table->integer('from_quantity');
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);

            $table->primary(['store_id', 'variant_id', 'from_quantity'], 'wholesale_bands_pkey');
            $table->foreign('store_id', 'wholesale_bands_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('variant_id', 'wholesale_bands_variant')->references('id')->on('catalog.variants')->cascadeOnDelete();
            $table->index('variant_id', 'wholesale_bands_variant_idx');
        });

        DB::statement('ALTER TABLE pricing.wholesale_bands ADD CONSTRAINT wholesale_bands_from CHECK (from_quantity >= 1)');
        DB::statement('ALTER TABLE pricing.wholesale_bands ADD CONSTRAINT wholesale_bands_positive CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE pricing.wholesale_bands ADD CONSTRAINT wholesale_bands_currency CHECK (currency_code ~ '^[A-Z]{3}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing.wholesale_bands');
    }
};
