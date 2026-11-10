<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.14, §5: each on store's sitemap, built by `BuildSitemapsJob` into files in private storage and
| served by the routes, never made in a shopper's request. One row a part of 50,000 addresses at most: part 1 at
| `/{store}/sitemap.xml`, part n at `/{store}/sitemap-{n}.xml`, each named in the index at `/sitemap.xml`.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.sitemaps', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->integer('part');
            $table->text('file_key');
            $table->integer('urls');
            $table->timestampTz('built_at');

            $table->primary(['store_id', 'part'], 'sitemaps_pkey');
            $table->foreign('store_id', 'sitemaps_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.sitemaps
                ADD CONSTRAINT sitemaps_part CHECK (part >= 1),
                ADD CONSTRAINT sitemaps_urls CHECK (urls BETWEEN 0 AND 50000),
                ADD CONSTRAINT sitemaps_file CHECK (char_length(file_key) BETWEEN 1 AND 500)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.sitemaps');
    }
};
