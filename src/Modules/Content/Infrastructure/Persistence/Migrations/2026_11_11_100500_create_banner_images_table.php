<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.6, §5: a banner's artwork, desktop and phone, one for every language (`any`) or, when the artwork
| has words on it, one per language. Which ones a placement takes is the code's rule (§1.6's table); the key is
| what the database can hold.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.banner_images', function (Blueprint $table) {
            $table->ulid('banner_id');
            $table->string('kind', 8);
            $table->string('locale', 3);
            $table->ulid('media_id');

            $table->primary(['banner_id', 'kind', 'locale'], 'banner_images_pkey');
            $table->foreign('banner_id', 'bi_banner')->references('id')->on('content.banners')->cascadeOnDelete();
            $table->foreign('media_id', 'bi_media')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index('media_id', 'bi_media_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.banner_images
                ADD CONSTRAINT bi_kind CHECK (kind IN ('DESKTOP', 'PHONE')),
                ADD CONSTRAINT bi_locale CHECK (locale IN ('any', 'ar', 'en'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.banner_images');
    }
};
