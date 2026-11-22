<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.13, §5: a project's words in one language, as a page's, plus its city. The address is unique per
| store and language here, and across every address ever held through content.slug_history.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.project_translations', function (Blueprint $table) {
            $table->ulid('project_id');
            $table->string('locale', 2);
            $table->ulid('store_id');
            $table->string('title', 150);
            $table->string('slug', 200);
            $table->string('city', 100)->nullable();
            $table->jsonb('body');
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 160)->nullable();

            $table->primary(['project_id', 'locale'], 'project_translations_pkey');
            $table->foreign('project_id', 'prt_project')->references('id')->on('content.projects')->cascadeOnDelete();
            $table->foreign('store_id', 'prt_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->unique(['store_id', 'locale', 'slug'], 'prt_slug_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.project_translations
                ADD CONSTRAINT prt_locale CHECK (locale IN ('ar', 'en')),
                ADD CONSTRAINT prt_title CHECK (char_length(btrim(title)) >= 1),
                ADD CONSTRAINT prt_slug CHECK (char_length(slug) BETWEEN 1 AND 200 AND strpos(slug, '/') = 0),
                ADD CONSTRAINT prt_body CHECK (COALESCE(jsonb_typeof(body -> 'blocks') = 'array', false))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.project_translations');
    }
};
