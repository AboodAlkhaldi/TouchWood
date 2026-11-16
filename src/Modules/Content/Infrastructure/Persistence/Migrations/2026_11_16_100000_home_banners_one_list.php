<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The home's banners become one list for both widths (shop-design.md §3.1, §10.3; content.md amendment 2): `HOME_HERO`
| takes a wide and a phone image and its own words - a title, a line and a button's text per language - and
| `HOME_PHONE` goes, its banners moved to the end of the store's `HOME_HERO` list in their order (before launch the data
| is mock only). The words are optional; their lengths are the code's (`SaveBannerHandler`), the columns as wide.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content.banners', function (Blueprint $table) {
            $table->string('title_ar', 60)->nullable();
            $table->string('title_en', 60)->nullable();
            $table->string('line_ar', 120)->nullable();
            $table->string('line_en', 120)->nullable();
            $table->string('button_ar', 24)->nullable();
            $table->string('button_en', 24)->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE content.banners AS b
               SET placement = 'HOME_HERO',
                   position = b.position + COALESCE((SELECT max(h.position) + 1 FROM content.banners h WHERE h.store_id = b.store_id AND h.placement = 'HOME_HERO'), 0)
             WHERE b.placement = 'HOME_PHONE'
            SQL);

        DB::statement('ALTER TABLE content.banners DROP CONSTRAINT banners_placement');
        DB::statement("ALTER TABLE content.banners ADD CONSTRAINT banners_placement CHECK (placement IN ('HOME_HERO', 'LISTING'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE content.banners DROP CONSTRAINT banners_placement');
        DB::statement("ALTER TABLE content.banners ADD CONSTRAINT banners_placement CHECK (placement IN ('HOME_HERO', 'HOME_PHONE', 'LISTING'))");

        Schema::table('content.banners', function (Blueprint $table) {
            $table->dropColumn(['title_ar', 'title_en', 'line_ar', 'line_en', 'button_ar', 'button_en']);
        });
    }
};
