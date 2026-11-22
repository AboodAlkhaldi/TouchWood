<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Addresses on Platform's places (access.md amendment 76): each keeps its country and, where it was
| picked from Platform's list, the region, city and district by id - NULL where typed. The ids are
| Platform's, kept by value with no foreign key: a module never keys into another's tables. The
| language is the page's at saving: the names the picked places filled in are in it (76(e)).
|
| Every address saved before this is test data and is deleted first (76(g), the owner's C6): none
| has a country, and none was picked from a list. B2B's saved copies keep their text; their link to
| the address is cleared by its own foreign key.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::table('access.addresses')->delete();

        Schema::table('access.addresses', function (Blueprint $table) {
            $table->char('country_code', 2);
            $table->ulid('region_id')->nullable();
            $table->ulid('city_id')->nullable();
            $table->ulid('district_id')->nullable();
            $table->char('language', 2);
        });

        // The last line of defence; the code refuses each of these first.
        DB::statement(<<<'SQL'
            ALTER TABLE access.addresses
                ADD CONSTRAINT addresses_country_code_format CHECK (country_code ~ '^[A-Z]{2}$'),
                ADD CONSTRAINT addresses_language CHECK (language IN ('ar', 'en')),
                ADD CONSTRAINT addresses_places_nested CHECK (
                    (city_id IS NULL OR region_id IS NOT NULL) AND (district_id IS NULL OR city_id IS NOT NULL)
                )
            SQL);
    }

    public function down(): void
    {
        Schema::table('access.addresses', function (Blueprint $table) {
            $table->dropColumn(['country_code', 'region_id', 'city_id', 'district_id', 'language']);
        });
    }
};
