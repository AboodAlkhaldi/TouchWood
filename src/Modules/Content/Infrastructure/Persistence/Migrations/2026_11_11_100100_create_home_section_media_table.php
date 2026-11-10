<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.4, §5: the files a home section shows, each with its RESTRICT key to platform.media (§1.1 rule
| 10): today the client logos (`LOGO`), ordered, each with its name per language - its description - and an
| optional link. The name and link sit on the row beside the file they describe (the build's pick: the spec's
| row names the file's columns; a logo's words belong to that logo). Gone with the section.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.home_section_media', function (Blueprint $table) {
            $table->ulid('section_id');
            $table->string('slot', 16);
            $table->integer('position');
            $table->ulid('media_id');
            $table->string('name_ar', 100);
            $table->string('name_en', 100)->nullable();
            $table->text('link')->nullable();

            $table->primary(['section_id', 'slot', 'position'], 'home_section_media_pkey');
            $table->foreign('section_id', 'hsm_section')->references('id')->on('content.home_sections')->cascadeOnDelete();
            $table->foreign('media_id', 'hsm_media')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index('media_id', 'hsm_media_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.home_section_media
                ADD CONSTRAINT hsm_slot CHECK (slot IN ('LOGO')),
                ADD CONSTRAINT hsm_position CHECK (position BETWEEN 0 AND 39),
                ADD CONSTRAINT hsm_name_ar CHECK (char_length(name_ar) BETWEEN 1 AND 100),
                ADD CONSTRAINT hsm_name_en CHECK (name_en IS NULL OR char_length(name_en) BETWEEN 1 AND 100)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.home_section_media');
    }
};
