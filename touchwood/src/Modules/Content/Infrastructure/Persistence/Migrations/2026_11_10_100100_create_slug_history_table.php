<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §5, §1.1 rule 15: every address a page, a post, a project, a blog category or a campaign's run
| ever had, per store, kind and language - so an old one, or the other language's, answers 301 to the
| current page. Unique per store, kind, language and address: one address names one item, now or before.
|
| No foreign key on item_id: it names a row of one of several tables (the kind says which), and the item's
| own delete removes its history (§1.1 rule 11: a deleted item's addresses are free again). The kinds are
| every Content item with a shop address (§4.5) - the build's pick; a later kind is a CHECK migration.
|
| Each CHECK is the last line: the code that writes history refuses the value first, and lands with its
| writer (PR 3). No column is nullable, so no CHECK can be passed with a NULL.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.slug_history', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->string('kind', 16);
            $table->ulid('item_id');
            $table->string('locale', 2);
            $table->text('slug');
            $table->timestampTz('created_at');

            $table->primary('id', 'slug_history_pkey');
            $table->foreign('store_id', 'slug_history_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->unique(['store_id', 'kind', 'locale', 'slug'], 'slug_history_unique');
            $table->index(['kind', 'item_id'], 'slug_history_item');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.slug_history
                ADD CONSTRAINT slug_history_kind CHECK (kind IN ('PAGE', 'POST', 'PROJECT', 'BLOG_CATEGORY', 'CAMPAIGN')),
                ADD CONSTRAINT slug_history_locale CHECK (locale IN ('ar', 'en')),
                ADD CONSTRAINT slug_history_slug CHECK (char_length(slug) BETWEEN 1 AND 200 AND strpos(slug, '/') = 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.slug_history');
    }
};
