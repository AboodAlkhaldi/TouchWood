<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.2, §1.11, §1.14, §5: a page's words in one language - title, address, body (the structured
| document of §1.2, never HTML), SEO title (70) and description (160). A draft may hold Arabic alone.
|
| The address is unique per store and language through a copy of the page's store (§5's "denormalized
| store_id"), and per store, kind and language across every address ever held through content.slug_history,
| which the code asks first (`SlugTaken`).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.page_translations', function (Blueprint $table) {
            $table->ulid('page_id');
            $table->string('locale', 2);
            $table->ulid('store_id');
            $table->string('title', 150);
            $table->string('slug', 200);
            $table->jsonb('body');
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 160)->nullable();

            $table->primary(['page_id', 'locale'], 'page_translations_pkey');
            $table->foreign('page_id', 'pt_page')->references('id')->on('content.pages')->cascadeOnDelete();
            $table->foreign('store_id', 'pt_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->unique(['store_id', 'locale', 'slug'], 'pt_slug_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.page_translations
                ADD CONSTRAINT pt_locale CHECK (locale IN ('ar', 'en')),
                ADD CONSTRAINT pt_title CHECK (char_length(btrim(title)) >= 1),
                ADD CONSTRAINT pt_slug CHECK (char_length(slug) BETWEEN 1 AND 200 AND strpos(slug, '/') = 0),
                ADD CONSTRAINT pt_body CHECK (COALESCE(jsonb_typeof(body -> 'blocks') = 'array', false))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.page_translations');
    }
};
