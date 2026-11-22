<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.14, §5, W10: a store's SEO defaults - the site's name, the title pattern (`:title | :site`) and a
| description per language, a share image, and the robots lines typed for the store (one shop path a line).
| Saved only with both languages (§1.1 rule 2: there is no Publish step), which the code refuses first
| (`SaveSeoDefaultsHandler`); no column of words is nullable, so no CHECK can be passed with a NULL.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.seo_defaults', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->string('site_name_ar', 60);
            $table->string('site_name_en', 60);
            $table->string('title_pattern_ar', 80);
            $table->string('title_pattern_en', 80);
            $table->string('description_ar', 160);
            $table->string('description_en', 160);
            $table->ulid('share_media_id')->nullable();
            $table->text('robots_extra')->default('');
            $table->ulid('updated_by')->nullable();
            $table->timestampTz('updated_at');

            $table->primary('store_id', 'seo_defaults_pkey');
            $table->foreign('store_id', 'seo_defaults_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('share_media_id', 'seo_defaults_share')->references('id')->on('platform.media')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.seo_defaults
                ADD CONSTRAINT seo_defaults_words CHECK (char_length(btrim(site_name_ar)) >= 1 AND char_length(btrim(site_name_en)) >= 1
                    AND char_length(btrim(description_ar)) >= 1 AND char_length(btrim(description_en)) >= 1),
                ADD CONSTRAINT seo_defaults_pattern CHECK (strpos(title_pattern_ar, ':title') > 0 AND strpos(title_pattern_en, ':title') > 0),
                ADD CONSTRAINT seo_defaults_robots CHECK (char_length(robots_extra) <= 10000)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.seo_defaults');
    }
};
