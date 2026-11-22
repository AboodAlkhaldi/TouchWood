<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.2, §5: the images a post's body names, as a page's (content.page_images).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.post_images', function (Blueprint $table) {
            $table->ulid('post_id');
            $table->string('locale', 2);
            $table->integer('ref');
            $table->ulid('media_id');
            $table->text('alt');

            $table->primary(['post_id', 'locale', 'ref'], 'post_images_pkey');
            $table->foreign('post_id', 'poi_post')->references('id')->on('content.blog_posts')->cascadeOnDelete();
            $table->foreign('media_id', 'poi_media')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index('media_id', 'poi_media_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.post_images
                ADD CONSTRAINT poi_locale CHECK (locale IN ('ar', 'en')),
                ADD CONSTRAINT poi_ref CHECK (ref >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.post_images');
    }
};
