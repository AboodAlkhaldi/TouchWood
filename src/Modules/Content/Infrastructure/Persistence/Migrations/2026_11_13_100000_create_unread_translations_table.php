<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.3 (the owner's P14, 2026-11-11): an item whose English Translate filled and nobody has said they read
| - a page, post or project, a banner, a pop-up, a home section, a store's announcement bar - keeps that mark across
| sessions, until "I read the translated English." is ticked. A row is the mark; no row, none. Publish, Schedule, Show
| and switching the bar on ask for the tick while it is there (`TranslationMarks`).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.unread_translations', function (Blueprint $table) {
            $table->string('kind', 20);
            // The item's id; a bar's is its store's (one a store).
            $table->ulid('item_id');
            $table->ulid('store_id');
            $table->ulid('marked_by')->nullable();
            $table->timestampTz('marked_at');

            $table->primary(['kind', 'item_id'], 'unread_translations_pkey');
            $table->foreign('store_id', 'unread_translations_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.unread_translations
                ADD CONSTRAINT unread_translations_kind CHECK (kind IN ('PAGE', 'POST', 'PROJECT', 'BANNER', 'POPUP', 'HOME_SECTION', 'BAR'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.unread_translations');
    }
};
