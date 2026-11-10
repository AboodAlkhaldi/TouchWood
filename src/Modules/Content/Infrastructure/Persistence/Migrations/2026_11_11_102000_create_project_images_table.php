<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.2, §5: the images a project's story names, as a page's (content.page_images).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.project_images', function (Blueprint $table) {
            $table->ulid('project_id');
            $table->string('locale', 2);
            $table->integer('ref');
            $table->ulid('media_id');
            $table->text('alt');

            $table->primary(['project_id', 'locale', 'ref'], 'project_images_pkey');
            $table->foreign('project_id', 'pri_project')->references('id')->on('content.projects')->cascadeOnDelete();
            $table->foreign('media_id', 'pri_media')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index('media_id', 'pri_media_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.project_images
                ADD CONSTRAINT pri_locale CHECK (locale IN ('ar', 'en')),
                ADD CONSTRAINT pri_ref CHECK (ref >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.project_images');
    }
};
