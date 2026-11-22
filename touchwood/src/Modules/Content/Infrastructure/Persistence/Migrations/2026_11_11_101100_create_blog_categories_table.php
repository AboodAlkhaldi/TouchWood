<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.12, §5: the blog's categories per store - name and address per language, both required (no
| Publish step, §1.1 rule 2), dragged into order. Deleted only when empty (`CategoryNotEmpty`). Their addresses
| are kept in content.slug_history as every Content address is.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.blog_categories', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->string('name_ar', 80);
            $table->string('name_en', 80);
            $table->string('slug_ar', 200);
            $table->string('slug_en', 200);
            $table->integer('position');
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'blog_categories_pkey');
            $table->foreign('store_id', 'blog_categories_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->unique(['store_id', 'slug_ar'], 'bc_slug_ar_unique');
            $table->unique(['store_id', 'slug_en'], 'bc_slug_en_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.blog_categories
                ADD CONSTRAINT bc_names CHECK (char_length(btrim(name_ar)) >= 1 AND char_length(btrim(name_en)) >= 1),
                ADD CONSTRAINT bc_slugs CHECK (char_length(slug_ar) >= 1 AND char_length(slug_en) >= 1 AND strpos(slug_ar, '/') = 0 AND strpos(slug_en, '/') = 0),
                ADD CONSTRAINT bc_position CHECK (position >= 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.blog_categories');
    }
};
