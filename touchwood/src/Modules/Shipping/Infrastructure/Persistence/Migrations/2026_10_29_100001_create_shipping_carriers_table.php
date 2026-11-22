<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A store's carriers (shipping.md §1.3, §5). The country is one the store ships to - Platform's list,
| checked by the handler under the store's carriers' lock, which Platform's removal of a country waits
| for (CarriersHoldCountry). Names are unique in the store ignoring case (step 3 Q4); the handler trims
| them. `free_above_minor` is its own free-shipping amount, set from step 4 (§1.5).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping.carriers', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->char('country_code', 2);
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->ulid('logo_media_id')->nullable();
            $table->string('phone', 16)->nullable();
            $table->string('website', 500)->nullable();
            $table->string('tracking_pattern', 500)->nullable();
            $table->bigInteger('free_above_minor')->nullable();
            $table->boolean('active');
            $table->integer('position');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'carriers_pkey');
            $table->foreign('store_id', 'carriers_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('logo_media_id', 'carriers_logo_media')->references('id')->on('platform.media')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE shipping.carriers
                ADD CONSTRAINT carriers_country CHECK (country_code ~ '^[A-Z]{2}$'),
                ADD CONSTRAINT carriers_name_ar CHECK (char_length(name_ar) BETWEEN 1 AND 100 AND name_ar = btrim(name_ar)),
                ADD CONSTRAINT carriers_name_en CHECK (char_length(name_en) BETWEEN 1 AND 100 AND name_en = btrim(name_en)),
                ADD CONSTRAINT carriers_phone CHECK (phone IS NULL OR phone ~ '^\+[1-9][0-9]{6,14}$'),
                ADD CONSTRAINT carriers_website CHECK (website IS NULL OR (website ILIKE 'https://%' AND char_length(website) <= 500)),
                ADD CONSTRAINT carriers_tracking_pattern CHECK (tracking_pattern IS NULL OR (
                    tracking_pattern ILIKE 'https://%'
                    AND char_length(tracking_pattern) <= 500
                    AND char_length(tracking_pattern) - char_length(replace(tracking_pattern, '{number}', '')) = 8)),
                ADD CONSTRAINT carriers_free_above CHECK (free_above_minor IS NULL OR free_above_minor >= 0),
                ADD CONSTRAINT carriers_position CHECK (position >= 1)
            SQL);

        DB::statement('CREATE UNIQUE INDEX carriers_name_ar_unique ON shipping.carriers (store_id, lower(name_ar))');
        DB::statement('CREATE UNIQUE INDEX carriers_name_en_unique ON shipping.carriers (store_id, lower(name_en))');
        DB::statement('CREATE INDEX carriers_by_store ON shipping.carriers (store_id, country_code, position, id)');
        DB::statement('CREATE INDEX carriers_by_logo ON shipping.carriers (logo_media_id) WHERE logo_media_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping.carriers');
    }
};
