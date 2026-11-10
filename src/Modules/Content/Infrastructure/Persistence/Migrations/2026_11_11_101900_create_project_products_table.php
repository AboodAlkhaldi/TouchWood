<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.13, §5: the products a project used, up to 20, in order - as a post's (blog_post_products).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.project_products', function (Blueprint $table) {
            $table->ulid('project_id');
            $table->ulid('product_id');
            $table->integer('position');

            $table->primary(['project_id', 'product_id'], 'project_products_pkey');
            $table->foreign('project_id', 'prp_project')->references('id')->on('content.projects')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE content.project_products ADD CONSTRAINT prp_position CHECK (position BETWEEN 0 AND 19)');
    }

    public function down(): void
    {
        Schema::dropIfExists('content.project_products');
    }
};
