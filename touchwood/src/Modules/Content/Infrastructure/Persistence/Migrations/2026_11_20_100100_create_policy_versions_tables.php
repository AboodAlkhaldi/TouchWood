<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| flags-2026-11.md S3.2, F14: each publish that changes a policy's text raises its version (1, 2, 3...) and keeps that
| version's text, so the exact text a customer accepted can always be shown to staff. A version is both languages'
| title and body as published (a policy publishes only with both), when and by whom; its body's images are kept by
| key in their own table, each with its RESTRICT key to platform.media - never inside the JSON (CONVENTIONS "Stores").
|
| A version is written once and never changed, and read by its number. A policy once published is never deleted (owner,
| 2026-11-20: only unpublished), so its versions stay; a policy never published has none to lose.
|
| CHECKs the Schema builder cannot write, in standard SQL (CONVENTIONS, "Database"), each refused first by the code;
| except the bodies' shape (`jsonb_typeof`), which has no standard form and copies pt_body (page_translations): the
| owner approved it on 2026-11-20 (CONVENTIONS: "something with no standard SQL form at all needs the owner's OK").
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.policy_versions', function (Blueprint $table) {
            $table->ulid('policy_id');
            $table->integer('number');
            $table->string('title_ar', 150);
            $table->string('title_en', 150);
            $table->jsonb('body_ar');
            $table->jsonb('body_en');
            $table->timestampTz('published_at');
            $table->ulid('published_by')->nullable();

            $table->primary(['policy_id', 'number'], 'policy_versions_pkey');
            $table->foreign('policy_id', 'polv_policy')->references('id')->on('content.policies')->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.policy_versions
                ADD CONSTRAINT polv_number CHECK (number >= 1),
                ADD CONSTRAINT polv_titles CHECK (char_length(TRIM(title_ar)) >= 1 AND char_length(TRIM(title_en)) >= 1),
                ADD CONSTRAINT polv_bodies CHECK (COALESCE(jsonb_typeof(body_ar -> 'blocks') = 'array' AND jsonb_typeof(body_en -> 'blocks') = 'array', false))
            SQL);

        Schema::create('content.policy_version_images', function (Blueprint $table) {
            $table->ulid('policy_id');
            $table->integer('number');
            $table->string('locale', 2);
            $table->integer('ref');
            $table->ulid('media_id');
            $table->text('alt');

            $table->primary(['policy_id', 'number', 'locale', 'ref'], 'policy_version_images_pkey');
            $table->foreign(['policy_id', 'number'], 'polvi_version')->references(['policy_id', 'number'])->on('content.policy_versions')->cascadeOnDelete();
            $table->foreign('media_id', 'polvi_media')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index('media_id', 'polvi_media_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.policy_version_images
                ADD CONSTRAINT polvi_locale CHECK (locale IN ('ar', 'en')),
                ADD CONSTRAINT polvi_ref CHECK (ref >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.policy_version_images');
        Schema::dropIfExists('content.policy_versions');
    }
};
