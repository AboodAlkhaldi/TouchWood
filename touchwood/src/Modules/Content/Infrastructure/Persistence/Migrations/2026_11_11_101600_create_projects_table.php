<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.13, §5: a finished client project, per store, Draft or Published, as a page. Its photos (1 to 20
| to publish), products and body images are their own tables.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.projects', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->string('status', 16)->default('DRAFT');
            $table->timestampTz('published_at')->nullable();
            $table->ulid('share_media_ar')->nullable();
            $table->ulid('share_media_en')->nullable();
            $table->integer('version')->default(1);
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'projects_pkey');
            $table->foreign('store_id', 'projects_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('share_media_ar', 'projects_share_ar')->references('id')->on('platform.media')->restrictOnDelete();
            $table->foreign('share_media_en', 'projects_share_en')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index(['store_id', 'status', 'updated_at'], 'projects_list_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.projects
                ADD CONSTRAINT projects_status CHECK (status IN ('DRAFT', 'PUBLISHED')),
                ADD CONSTRAINT projects_published CHECK ((status = 'PUBLISHED') = (published_at IS NOT NULL)),
                ADD CONSTRAINT projects_version CHECK (version >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.projects');
    }
};
