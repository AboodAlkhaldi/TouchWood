<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Platform spec §5.2. No status column: stores have no lifecycle (handoff §16).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform.stores', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 8)->unique();
            $table->jsonb('name');
            $table->char('country_code', 2);
            $table->char('currency_code', 3);
            $table->integer('tax_rate_basis_points');
            $table->string('timezone', 64);
            $table->smallInteger('position')->default(0);
            $table->timestampsTz();

            $table->foreign('currency_code')->references('code')->on('platform.currencies')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE platform.stores
                ADD CONSTRAINT stores_code_format CHECK (code ~ '^[a-z]{2,8}$'),
                ADD CONSTRAINT stores_country_code_format CHECK (country_code ~ '^[A-Z]{2}$'),
                ADD CONSTRAINT stores_tax_rate_range CHECK (tax_rate_basis_points BETWEEN 0 AND 10000),
                ADD CONSTRAINT stores_position_range CHECK (position >= 0),
                ADD CONSTRAINT stores_name_translated
                    CHECK (jsonb_exists(name, 'ar') AND jsonb_exists(name, 'en'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform.stores');
    }
};
