<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.2, §5: the images a page's body names (`![description](image:3)` is ref 3 of that language),
| each with its RESTRICT key to platform.media and the description the block carries. A row no document names
| is removed on save; every row goes with the page.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.page_images', function (Blueprint $table) {
            $table->ulid('page_id');
            $table->string('locale', 2);
            $table->integer('ref');
            $table->ulid('media_id');
            $table->text('alt');

            $table->primary(['page_id', 'locale', 'ref'], 'page_images_pkey');
            $table->foreign('page_id', 'pi_page')->references('id')->on('content.pages')->cascadeOnDelete();
            $table->foreign('media_id', 'pi_media')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index('media_id', 'pi_media_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.page_images
                ADD CONSTRAINT pi_locale CHECK (locale IN ('ar', 'en')),
                ADD CONSTRAINT pi_ref CHECK (ref >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.page_images');
    }
};
