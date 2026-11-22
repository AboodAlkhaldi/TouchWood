<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.10, §5: a pop-up - the staff's name for it, title (80) and text (240) per language, an optional
| image, button words and a link, a delay of 3 to 60 seconds, its dates. One live at a time per store: a second
| overlapping one is refused on scheduling, under the store's lock (`PopupClash`), which no CHECK can say.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.popups', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->string('name', 100);
            $table->string('title_ar', 80);
            $table->string('title_en', 80)->nullable();
            $table->string('text_ar', 240);
            $table->string('text_en', 240)->nullable();
            $table->string('button_ar', 40)->nullable();
            $table->string('button_en', 40)->nullable();
            $table->text('link')->nullable();
            $table->smallInteger('delay_seconds')->default(8);
            $table->ulid('media_id')->nullable();
            $table->boolean('is_scheduled')->default(false);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->integer('version')->default(1);
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'popups_pkey');
            $table->foreign('store_id', 'popups_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('media_id', 'popups_media')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index(['store_id', 'is_scheduled'], 'popups_store_idx');
            $table->index('media_id', 'popups_media_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.popups
                ADD CONSTRAINT popups_delay CHECK (delay_seconds BETWEEN 3 AND 60),
                ADD CONSTRAINT popups_name CHECK (char_length(btrim(name)) >= 1),
                ADD CONSTRAINT popups_scheduled CHECK (NOT is_scheduled OR (starts_at IS NOT NULL AND title_en IS NOT NULL AND text_en IS NOT NULL AND (button_ar IS NULL OR button_en IS NOT NULL))),
                ADD CONSTRAINT popups_button CHECK ((button_ar IS NULL) = (link IS NULL)),
                ADD CONSTRAINT popups_dates CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at),
                ADD CONSTRAINT popups_version CHECK (version >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.popups');
    }
};
