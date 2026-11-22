<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| flags-2026-11.md S4.4, content.md §1.13 (amendment 4): a project's YouTube videos - at most 10 (F21), in order, each
| once, each kept as the video's 11-character id with an optional title per language (100 characters, the column's
| own). Saved with the project, whole, as its photos are. The id's characters (A-Z a-z 0-9 _ -) are the code's rule
| (Platform's YouTubeLink): a pattern CHECK has no standard SQL form (CONVENTIONS, 2026-11-11), so the column holds
| only its length.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.project_videos', function (Blueprint $table) {
            $table->ulid('project_id');
            $table->integer('position');
            $table->string('video_id', 11);
            $table->string('title_ar', 100)->nullable();
            $table->string('title_en', 100)->nullable();

            $table->primary(['project_id', 'position'], 'project_videos_pkey');
            $table->unique(['project_id', 'video_id'], 'pv_once');
            $table->foreign('project_id', 'pv_project')->references('id')->on('content.projects')->cascadeOnDelete();
        });

        // Standard SQL only (CONVENTIONS, 2026-11-11): a CHECK has no builder form. NULL-safe: a title left NULL passes, a blank one does not.
        DB::statement('ALTER TABLE content.project_videos ADD CONSTRAINT pv_position CHECK (position BETWEEN 0 AND 9)');
        DB::statement('ALTER TABLE content.project_videos ADD CONSTRAINT pv_video_id CHECK (char_length(video_id) = 11)');
        DB::statement("ALTER TABLE content.project_videos ADD CONSTRAINT pv_titles CHECK ((title_ar IS NULL OR TRIM(title_ar) <> '') AND (title_en IS NULL OR TRIM(title_en) <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('content.project_videos');
    }
};
