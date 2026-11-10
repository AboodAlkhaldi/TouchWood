<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.12, §5: a blog post, as a page, plus its category (optional; SET NULL, though the code refuses to
| delete a category holding posts first) and its cover image (RESTRICT). A draft may have no cover yet; Publish
| asks for one (the build's pick: the blog's cards draw it, §4.5 H3).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.blog_posts', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->string('status', 16)->default('DRAFT');
            $table->timestampTz('published_at')->nullable();
            $table->ulid('category_id')->nullable();
            $table->ulid('cover_media_id')->nullable();
            $table->ulid('share_media_ar')->nullable();
            $table->ulid('share_media_en')->nullable();
            $table->integer('version')->default(1);
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'blog_posts_pkey');
            $table->foreign('store_id', 'blog_posts_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('category_id', 'blog_posts_category')->references('id')->on('content.blog_categories')->nullOnDelete();
            $table->foreign('cover_media_id', 'blog_posts_cover')->references('id')->on('platform.media')->restrictOnDelete();
            $table->foreign('share_media_ar', 'blog_posts_share_ar')->references('id')->on('platform.media')->restrictOnDelete();
            $table->foreign('share_media_en', 'blog_posts_share_en')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index(['store_id', 'status', 'updated_at'], 'blog_posts_list_idx');
            $table->index('category_id', 'blog_posts_category_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.blog_posts
                ADD CONSTRAINT posts_status CHECK (status IN ('DRAFT', 'PUBLISHED')),
                ADD CONSTRAINT posts_published CHECK ((status = 'PUBLISHED') = (published_at IS NOT NULL)),
                ADD CONSTRAINT posts_cover CHECK (status = 'DRAFT' OR cover_media_id IS NOT NULL),
                ADD CONSTRAINT posts_version CHECK (version >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.blog_posts');
    }
};
