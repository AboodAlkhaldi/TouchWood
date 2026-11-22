<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.12, §5: a post's words in one language, as a page's, plus its one-line summary and the cover's
| description. The address is unique per store and language here, and across every address ever held through
| content.slug_history (`SlugTaken`, asked first).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.blog_post_translations', function (Blueprint $table) {
            $table->ulid('post_id');
            $table->string('locale', 2);
            $table->ulid('store_id');
            $table->string('title', 150);
            $table->string('slug', 200);
            $table->string('summary', 200)->nullable();
            $table->jsonb('body');
            $table->string('cover_alt', 200)->nullable();
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 160)->nullable();

            $table->primary(['post_id', 'locale'], 'blog_post_translations_pkey');
            $table->foreign('post_id', 'bpt_post')->references('id')->on('content.blog_posts')->cascadeOnDelete();
            $table->foreign('store_id', 'bpt_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->unique(['store_id', 'locale', 'slug'], 'bpt_slug_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.blog_post_translations
                ADD CONSTRAINT bpt_locale CHECK (locale IN ('ar', 'en')),
                ADD CONSTRAINT bpt_title CHECK (char_length(btrim(title)) >= 1),
                ADD CONSTRAINT bpt_slug CHECK (char_length(slug) BETWEEN 1 AND 200 AND strpos(slug, '/') = 0),
                ADD CONSTRAINT bpt_body CHECK (COALESCE(jsonb_typeof(body -> 'blocks') = 'array', false))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.blog_post_translations');
    }
};
