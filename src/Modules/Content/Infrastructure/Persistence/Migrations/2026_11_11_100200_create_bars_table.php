<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.5, §5: the announcement bar, one per store - one line of 120 characters per language, an optional
| link, on or off with an optional start and end. Switched on only with both languages (§1.1 rule 2), which the
| code refuses first (`SaveBarHandler`).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.bars', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->string('text_ar', 120)->nullable();
            $table->string('text_en', 120)->nullable();
            $table->text('link')->nullable();
            $table->boolean('is_on')->default(false);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->integer('version')->default(1);
            $table->ulid('updated_by')->nullable();
            $table->timestampTz('updated_at');

            $table->primary('store_id', 'bars_pkey');
            $table->foreign('store_id', 'bars_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.bars
                ADD CONSTRAINT bars_on_words CHECK (NOT is_on OR (text_ar IS NOT NULL AND text_en IS NOT NULL)),
                ADD CONSTRAINT bars_dates CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at),
                ADD CONSTRAINT bars_version CHECK (version >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.bars');
    }
};
