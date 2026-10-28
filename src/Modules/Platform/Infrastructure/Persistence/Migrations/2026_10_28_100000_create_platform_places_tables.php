<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The places and each store's ship-to countries (platform.md, stage 7 (a) and (b); shipping.md §1.3).
|
| - address_countries: the countries a customer may choose in an address, set by Super Admins.
|   Names are PHP's country names, so only the code is kept.
| - places: per country, its regions, cities and districts in both languages. Never deleted, only
|   retired (shipping.md §9.3 #4); names are unique under one parent, in each language (step 1 Q6).
| - store_countries: the countries each store ships to; a country in one store at most (R3).
|
| Every store's own country goes on the address list and on its own ship-to list here, as it will
| for every store opened later (shipping.md §1.3).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform.address_countries', function (Blueprint $table) {
            $table->char('country_code', 2)->primary();
            $table->boolean('retired')->default(false);
            $table->timestampsTz();
        });

        Schema::create('platform.places', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('country_code', 2);
            $table->string('kind', 10);
            $table->ulid('parent_id')->nullable();
            $table->jsonb('name');
            $table->boolean('retired')->default(false);
            $table->timestampsTz();

            $table->foreign('country_code')->references('country_code')->on('platform.address_countries')->restrictOnDelete();
            $table->index('parent_id');
            $table->index(['country_code', 'kind']);
        });

        // After the table exists: its own key must be there before a key may point at it.
        Schema::table('platform.places', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('platform.places')->restrictOnDelete();
        });

        Schema::create('platform.store_countries', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->char('country_code', 2);
            $table->timestampTz('created_at');

            $table->primary(['store_id', 'country_code']);
            $table->unique('country_code', 'store_countries_one_store_per_country');
            $table->foreign('store_id')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('country_code')->references('country_code')->on('platform.address_countries')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE platform.address_countries
                ADD CONSTRAINT address_countries_code_format CHECK (country_code ~ '^[A-Z]{2}$')
            SQL);

        // A region has no parent; a city and a district have one. Each name present and not blank
        // in both languages; COALESCE so a missing key fails rather than passing as NULL.
        DB::statement(<<<'SQL'
            ALTER TABLE platform.places
                ADD CONSTRAINT places_kind CHECK (kind IN ('REGION', 'CITY', 'DISTRICT')),
                ADD CONSTRAINT places_parent_by_kind CHECK ((kind = 'REGION') = (parent_id IS NULL)),
                ADD CONSTRAINT places_name_translated CHECK (
                    COALESCE(length(btrim(name->>'ar')) BETWEEN 1 AND 100, false)
                    AND COALESCE(length(btrim(name->>'en')) BETWEEN 1 AND 100, false)
                )
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX places_unique_name_ar
                ON platform.places (country_code, kind, COALESCE(parent_id, ''), lower(name->>'ar'))
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX places_unique_name_en
                ON platform.places (country_code, kind, COALESCE(parent_id, ''), lower(name->>'en'))
            SQL);

        // Which of two stores in one country ships there is the owner's to decide, never this file's.
        /** @var list<object{country_code: string, codes: string}> $shared */
        $shared = DB::select(<<<'SQL'
            SELECT country_code, string_agg(code, ', ' ORDER BY code) AS codes
            FROM platform.stores
            GROUP BY country_code
            HAVING count(*) > 1
            SQL);

        if ($shared !== []) {
            $lines = array_map(static fn (object $row): string => "{$row->country_code}: {$row->codes}", $shared);

            throw new RuntimeException('Stores share a country, and a country ships from one store only now (shipping.md §1.3). Decide which store keeps it first: '.implode('; ', $lines).'.');
        }

        DB::statement(<<<'SQL'
            INSERT INTO platform.address_countries (country_code, retired, created_at, updated_at)
            SELECT DISTINCT country_code, false, now(), now() FROM platform.stores
            SQL);
        DB::statement(<<<'SQL'
            INSERT INTO platform.store_countries (store_id, country_code, created_at)
            SELECT id, country_code, now() FROM platform.stores
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform.store_countries');
        Schema::dropIfExists('platform.places');
        Schema::dropIfExists('platform.address_countries');
    }
};
