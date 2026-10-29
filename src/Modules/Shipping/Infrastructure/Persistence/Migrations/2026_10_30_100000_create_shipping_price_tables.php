<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A carrier's prices (shipping.md §1.4, §5): groups of one amount, each with its places. A price is on
| one place of Platform's - a region, a city or a district (owner, 2026-10-29: a whole region may be
| priced, in every country) - so one place column, not a city and a district. Rule 3, one price per
| place per carrier, is the unique (carrier, place); rule 4, one group per amount per carrier, the
| unique (carrier, amount). A place row copies its group's carrier through a composite key, so the two
| can never disagree, and goes with its group, as the group goes with its carrier.
|
| `uncovered_counts` keeps each store's "Places no carrier serves" count for the menu (§1.6), counted
| again after every change that can move it.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping.price_groups', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('carrier_id');
            $table->ulid('store_id');
            $table->bigInteger('amount_minor');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'price_groups_pkey');
            $table->foreign('carrier_id', 'price_groups_carrier')->references('id')->on('shipping.carriers')->cascadeOnDelete();
            $table->foreign('store_id', 'price_groups_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->unique(['carrier_id', 'amount_minor'], 'price_groups_one_per_amount');
            $table->unique(['id', 'carrier_id'], 'price_groups_id_carrier');
        });

        DB::statement('ALTER TABLE shipping.price_groups ADD CONSTRAINT price_groups_amount CHECK (amount_minor BETWEEN 0 AND 100000000)');

        Schema::create('shipping.price_places', function (Blueprint $table) {
            $table->id();
            $table->ulid('store_id');
            $table->ulid('group_id');
            $table->ulid('carrier_id');
            $table->ulid('place_id');

            $table->foreign(['group_id', 'carrier_id'], 'price_places_group')->references(['id', 'carrier_id'])->on('shipping.price_groups')->cascadeOnDelete();
            $table->foreign('place_id', 'price_places_place')->references('id')->on('platform.places')->restrictOnDelete();
            $table->unique(['carrier_id', 'place_id'], 'price_places_one_per_place');
            $table->index(['group_id'], 'price_places_by_group');
            $table->index(['store_id', 'place_id'], 'price_places_by_place');
        });

        Schema::create('shipping.uncovered_counts', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->integer('count');
            $table->timestampTz('counted_at');

            $table->primary('store_id', 'uncovered_counts_pkey');
            $table->foreign('store_id', 'uncovered_counts_store')->references('id')->on('platform.stores')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE shipping.uncovered_counts ADD CONSTRAINT uncovered_counts_count CHECK (count >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping.uncovered_counts');
        Schema::dropIfExists('shipping.price_places');
        Schema::dropIfExists('shipping.price_groups');
    }
};
