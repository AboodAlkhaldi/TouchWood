<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Access\Application\Address\GiveEveryStoreAnAddressFormat;

/*
| Access spec §1.9 and §5.5: a customer's addresses, and each store's address form. An address
| belongs to one store — the country it is in — and a customer has one default per store. Every
| store already open starts with the standard scheme (amendment 41); a store opened later gets it
| when Platform says so (WriteStartingAddressFormat).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access.store_address_formats', function (Blueprint $table) {
            $table->ulid('store_id')->primary();
            $table->jsonb('fields');
            $table->text('display_template');
            $table->timestampTz('updated_at');

            $table->foreign('store_id')->references('id')->on('platform.stores')->cascadeOnDelete();
        });

        Schema::create('access.addresses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('customer_id');
            $table->ulid('store_id');
            $table->string('label', 50);
            $table->string('recipient_name', 100);
            $table->string('phone', 16);
            $table->jsonb('fields');
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->foreign('customer_id')->references('id')->on('access.customers')->cascadeOnDelete();
            $table->foreign('store_id')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->index(['customer_id', 'store_id']);
        });

        // The last line of defence; the code refuses each of these first (spec §5, handoff §5.3).
        DB::statement("ALTER TABLE access.addresses ADD CONSTRAINT addresses_phone_format CHECK (phone ~ '^\\+[1-9][0-9]{6,14}$')");
        DB::statement('ALTER TABLE access.addresses ADD CONSTRAINT addresses_map_pin_together CHECK (num_nulls(latitude, longitude) <> 1)');
        DB::statement('ALTER TABLE access.addresses ADD CONSTRAINT addresses_map_pin_range CHECK (latitude IS NULL OR (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180))');
        DB::statement("ALTER TABLE access.addresses ADD CONSTRAINT addresses_fields_object CHECK (jsonb_typeof(fields) = 'object')");
        DB::statement('CREATE UNIQUE INDEX addresses_one_default_per_store ON access.addresses (customer_id, store_id) WHERE is_default');

        // The stores this installation already has can take addresses at once (amendment 41); one
        // opened later is served by WriteStartingAddressFormat.
        app(GiveEveryStoreAnAddressFormat::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('access.addresses');
        Schema::dropIfExists('access.store_address_formats');
    }
};
