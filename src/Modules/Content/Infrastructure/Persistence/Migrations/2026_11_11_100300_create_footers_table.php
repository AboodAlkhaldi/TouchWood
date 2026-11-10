<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.5, §5: the footer per store - a line about the store, phone, email, an address line, up to 3
| columns of up to 8 links (each a text per language and a link) and the copyright line, per language. Saved
| only with both languages of each line filled (§1.1 rule 2), which the code refuses first.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.footers', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->string('about_ar', 200)->nullable();
            $table->string('about_en', 200)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('address_ar', 200)->nullable();
            $table->string('address_en', 200)->nullable();
            $table->string('copyright_ar', 120)->nullable();
            $table->string('copyright_en', 120)->nullable();
            $table->jsonb('columns')->default('[]');
            $table->integer('version')->default(1);
            $table->ulid('updated_by')->nullable();
            $table->timestampTz('updated_at');

            $table->primary('store_id', 'footers_pkey');
            $table->foreign('store_id', 'footers_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.footers
                ADD CONSTRAINT footers_about CHECK ((about_ar IS NULL) = (about_en IS NULL)),
                ADD CONSTRAINT footers_address CHECK ((address_ar IS NULL) = (address_en IS NULL)),
                ADD CONSTRAINT footers_copyright CHECK ((copyright_ar IS NULL) = (copyright_en IS NULL)),
                ADD CONSTRAINT footers_columns CHECK (jsonb_typeof(columns) = 'array' AND jsonb_array_length(columns) <= 3),
                ADD CONSTRAINT footers_version CHECK (version >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.footers');
    }
};
