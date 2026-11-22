<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.13, §5: a project's photos, ordered, at most 20, each with its RESTRICT key to platform.media.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.project_photos', function (Blueprint $table) {
            $table->ulid('project_id');
            $table->integer('position');
            $table->ulid('media_id');

            $table->primary(['project_id', 'position'], 'project_photos_pkey');
            $table->foreign('project_id', 'pp_project')->references('id')->on('content.projects')->cascadeOnDelete();
            $table->foreign('media_id', 'pp_media')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index('media_id', 'pp_media_idx');
        });

        DB::statement('ALTER TABLE content.project_photos ADD CONSTRAINT pp_position CHECK (position BETWEEN 0 AND 19)');
    }

    public function down(): void
    {
        Schema::dropIfExists('content.project_photos');
    }
};
