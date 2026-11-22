<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.9, §5: a short message above product lists, per category (with optional dates) or per campaign
| run (while the run is live). Exactly one of the two. Both languages, there being no Publish step (§1.1 rule 2).
|
| `run_id` stays without its key until campaign runs exist (plan PR 6, which adds the CASCADE key); until then
| the code offers no campaign bar. `category_id` is RESTRICT: Content's CatalogUsage refuses Catalog's delete first.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.smart_bars', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->string('scope', 8);
            $table->ulid('category_id')->nullable();
            $table->ulid('run_id')->nullable();
            $table->string('message_ar', 100);
            $table->string('message_en', 100);
            $table->text('link')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'smart_bars_pkey');
            $table->foreign('store_id', 'smart_bars_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('category_id', 'smart_bars_category')->references('id')->on('catalog.categories')->restrictOnDelete();
            $table->index(['store_id', 'scope'], 'smart_bars_store_idx');
            $table->index('category_id', 'smart_bars_category_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.smart_bars
                ADD CONSTRAINT sb_scope CHECK (
                    (scope = 'CATEGORY' AND category_id IS NOT NULL AND run_id IS NULL)
                    OR (scope = 'CAMPAIGN' AND run_id IS NOT NULL AND category_id IS NULL AND starts_at IS NULL AND ends_at IS NULL)
                ),
                ADD CONSTRAINT sb_message CHECK (char_length(btrim(message_ar)) >= 1 AND char_length(btrim(message_en)) >= 1),
                ADD CONSTRAINT sb_dates CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.smart_bars');
    }
};
