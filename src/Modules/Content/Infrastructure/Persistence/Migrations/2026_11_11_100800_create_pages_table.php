<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.11, §5: a page, per store (Q11 (a)), Draft or Published - published at once, with no dates
| (§1.1 rule 5). Its words are per language in page_translations; its share image per language here, each with
| its RESTRICT key (§1.1 rule 10).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.pages', function (Blueprint $table) {
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

            $table->primary('id', 'pages_pkey');
            $table->foreign('store_id', 'pages_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('share_media_ar', 'pages_share_ar')->references('id')->on('platform.media')->restrictOnDelete();
            $table->foreign('share_media_en', 'pages_share_en')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index(['store_id', 'status', 'updated_at'], 'pages_list_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.pages
                ADD CONSTRAINT pages_status CHECK (status IN ('DRAFT', 'PUBLISHED')),
                ADD CONSTRAINT pages_published CHECK ((status = 'PUBLISHED') = (published_at IS NOT NULL)),
                ADD CONSTRAINT pages_version CHECK (version >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.pages');
    }
};
