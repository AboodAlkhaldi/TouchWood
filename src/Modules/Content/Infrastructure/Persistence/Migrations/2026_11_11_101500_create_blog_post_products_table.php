<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.12, §5: up to 8 products linked to a post, in order. No key to Catalog's products, as §5 has
| it: only ready products are linked, and Catalog deletes only drafts; an archived one stays listed in the
| panel, marked, and vanishes from the shop (§1.1 rule 17).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.blog_post_products', function (Blueprint $table) {
            $table->ulid('post_id');
            $table->ulid('product_id');
            $table->integer('position');

            $table->primary(['post_id', 'product_id'], 'blog_post_products_pkey');
            $table->foreign('post_id', 'bpp_post')->references('id')->on('content.blog_posts')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE content.blog_post_products ADD CONSTRAINT bpp_position CHECK (position BETWEEN 0 AND 7)');
    }

    public function down(): void
    {
        Schema::dropIfExists('content.blog_post_products');
    }
};
