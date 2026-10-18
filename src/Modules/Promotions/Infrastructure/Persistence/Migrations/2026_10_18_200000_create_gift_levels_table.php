<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| promotions.md §5, the gifts' part: a store's gift levels — "over 1,000 SAR a pen" — each an amount in
| the store's currency, one Catalog variant as its gift, its buyers, optional dates and a switch.
|
| Every rule is also the code's (handoff §5.3); every CHECK on a nullable column is written so that
| NULL fails it where a value is needed (lessons 35, 162).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions.gift_levels', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->ulid('variant_id');
            $table->boolean('for_public');
            $table->boolean('for_company');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('active');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'gift_levels_pkey');
            $table->foreign('store_id', 'gift_levels_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('currency_code', 'gift_levels_currency')->references('code')->on('platform.currencies')->restrictOnDelete();
            // A gift's variant is never deleted from under it: Catalog asks first (amendment 19).
            $table->foreign('variant_id', 'gift_levels_variant')->references('id')->on('catalog.variants')->restrictOnDelete();
            $table->unique(['id', 'store_id'], 'gift_levels_id_store');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE promotions.gift_levels
                ADD CONSTRAINT gift_levels_amount CHECK (amount_minor > 0),
                ADD CONSTRAINT gift_levels_audiences CHECK (for_public OR for_company),
                ADD CONSTRAINT gift_levels_dates CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
            SQL);

        // The levels an order reaches, highest first (§5, indexes).
        DB::statement('CREATE INDEX gift_levels_by_amount ON promotions.gift_levels (store_id, amount_minor DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions.gift_levels');
    }
};
