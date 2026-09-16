<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Platform spec §5.1. The CHECK constraints repeat the domain rules so the database refuses
| bad rows even if they arrive by another path.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform.currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->smallInteger('exponent');
            $table->jsonb('name');
            $table->jsonb('abbreviation');
            $table->string('sign', 8)->nullable();
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE platform.currencies
                ADD CONSTRAINT currencies_code_format CHECK (code ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT currencies_exponent_range CHECK (exponent BETWEEN 0 AND 6),
                ADD CONSTRAINT currencies_name_translated
                    CHECK (jsonb_exists(name, 'ar') AND jsonb_exists(name, 'en')),
                ADD CONSTRAINT currencies_abbreviation_translated
                    CHECK (jsonb_exists(abbreviation, 'ar') AND jsonb_exists(abbreviation, 'en'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform.currencies');
    }
};
