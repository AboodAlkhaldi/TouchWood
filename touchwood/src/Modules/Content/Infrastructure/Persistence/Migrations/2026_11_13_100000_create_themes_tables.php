<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.8, §5: a theme - the 11 parts only, never a colour - for the stores it picks. Its start and end are kept
| as typed (`*_local`, a wall-clock time with no zone) and, per store, as that store's own moments (`theme_stores`):
| 00:00 is each store's own midnight (Q1 (a)). A draft has no store rows of its own state: `is_scheduled` says whether
| it is scheduled; SCHEDULED, LIVE and ENDED are read from each store's moments and the clock (§4.1).
|
| One theme live per store at a time: refused in code under each store's lock (`ThemeClash`), which no CHECK can say.
| `slug` is the name the page's `data-campaign` carries (frontend.md §1.8), made once, Latin, unique.
|
| A part's words and links are in `theme_parts`; its images in `theme_part_images`, each media id in a column with its
| RESTRICT key, never in JSON (§1.1 rule 10). `extras` holds words only.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.themes', function (Blueprint $table) {
            $table->ulid('id');
            $table->string('slug', 60);
            $table->string('name_ar', 100);
            $table->string('name_en', 100)->nullable();
            $table->boolean('is_scheduled')->default(false);
            $table->timestamp('starts_at_local', 0);
            $table->timestamp('ends_at_local', 0);
            $table->integer('version')->default(1);
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'themes_pkey');
            $table->unique('slug', 'themes_slug');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.themes
                ADD CONSTRAINT themes_slug_shape CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$' AND slug <> 'base'),
                ADD CONSTRAINT themes_name CHECK (char_length(btrim(name_ar)) >= 1 AND (name_en IS NULL OR char_length(btrim(name_en)) >= 1)),
                ADD CONSTRAINT themes_scheduled CHECK (NOT is_scheduled OR name_en IS NOT NULL),
                ADD CONSTRAINT themes_dates CHECK (ends_at_local > starts_at_local),
                ADD CONSTRAINT themes_version CHECK (version >= 1)
            SQL);

        Schema::create('content.theme_stores', function (Blueprint $table) {
            $table->ulid('theme_id');
            $table->ulid('store_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');

            $table->primary(['theme_id', 'store_id'], 'theme_stores_pkey');
            $table->foreign('theme_id', 'theme_stores_theme')->references('id')->on('content.themes')->cascadeOnDelete();
            $table->foreign('store_id', 'theme_stores_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->index(['store_id', 'starts_at'], 'theme_stores_store_idx');
        });

        DB::statement('ALTER TABLE content.theme_stores ADD CONSTRAINT theme_stores_dates CHECK (ends_at > starts_at)');

        Schema::create('content.theme_parts', function (Blueprint $table) {
            $table->ulid('theme_id');
            $table->string('part', 16);
            $table->string('text_ar', 200)->nullable();
            $table->string('text_en', 200)->nullable();
            $table->text('link')->nullable();
            $table->jsonb('extras')->default('{}');

            $table->primary(['theme_id', 'part'], 'theme_parts_pkey');
            $table->foreign('theme_id', 'theme_parts_theme')->references('id')->on('content.themes')->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.theme_parts
                ADD CONSTRAINT theme_parts_part CHECK (part IN ('ANNOUNCEMENT', 'HERO', 'PROMISES', 'POINTS', 'LOGOS', 'FLAG', 'BADGE', 'LISTING', 'CART', 'FOOTER', 'BACKDROP')),
                ADD CONSTRAINT theme_parts_extras CHECK (jsonb_typeof(extras) = 'object')
            SQL);

        Schema::create('content.theme_part_images', function (Blueprint $table) {
            $table->ulid('theme_id');
            $table->string('part', 16);
            $table->string('slot', 16);
            $table->string('locale', 3);
            $table->ulid('media_id');

            $table->primary(['theme_id', 'part', 'slot', 'locale'], 'theme_part_images_pkey');
            $table->foreign(['theme_id', 'part'], 'tpi_part')->references(['theme_id', 'part'])->on('content.theme_parts')->cascadeOnDelete();
            $table->foreign('media_id', 'tpi_media')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index('media_id', 'tpi_media_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.theme_part_images
                ADD CONSTRAINT tpi_slot CHECK (slot IN ('DESKTOP', 'PHONE', 'BAND', 'FLAG', 'RIBBON', 'START', 'END')),
                ADD CONSTRAINT tpi_locale CHECK (locale IN ('any', 'ar', 'en'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.theme_part_images');
        Schema::dropIfExists('content.theme_parts');
        Schema::dropIfExists('content.theme_stores');
        Schema::dropIfExists('content.themes');
    }
};
