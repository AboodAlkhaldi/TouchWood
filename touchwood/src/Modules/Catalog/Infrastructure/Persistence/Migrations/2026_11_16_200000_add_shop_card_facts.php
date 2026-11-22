<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| The shop to the design (shop-design.md §10.1, §10.5, §10.6; catalog.md amendment 48):
|
| - **`products.unit`**: how a product is sold - a piece, a set, a pair, a metre, a carton or a box - a piece unless
|   chosen (§10.1). The code refuses any other first (`ProductUnit`).
| - **`categories.description_ar/_en`**: a category's optional line under its page's heading (§10.5).
| - **The card's facts on the listing row**, each side's (individuals; companies and guests): its brand's name, its
|   unit, how many sizes the side can order now and, for one, what a card's Add to cart adds (`{variant, mode,
|   minimum}`), the product's next photos for a desktop's hover, and when it was listed in the store. Empty in every row
|   until the listing is written again: `php artisan catalog:listing:rebuild` after this is deployed.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE catalog.products ADD COLUMN unit varchar(12) NOT NULL DEFAULT 'PIECE'");
        DB::statement("ALTER TABLE catalog.products ADD CONSTRAINT products_unit CHECK (unit IN ('PIECE', 'SET', 'PAIR', 'METRE', 'CARTON', 'BOX'))");

        DB::statement('ALTER TABLE catalog.categories ADD COLUMN description_ar varchar(300) NULL, ADD COLUMN description_en varchar(300) NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE catalog.listing
                ADD COLUMN brand_name varchar(100) NULL,
                ADD COLUMN unit varchar(12) NULL,
                ADD COLUMN sizes_individuals smallint NULL,
                ADD COLUMN sizes_companies smallint NULL,
                ADD COLUMN one_size_individuals jsonb NULL,
                ADD COLUMN one_size_companies jsonb NULL,
                ADD COLUMN more_photos jsonb NULL,
                ADD COLUMN listed_at timestamptz NULL
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE catalog.listing
                DROP COLUMN IF EXISTS brand_name, DROP COLUMN IF EXISTS unit, DROP COLUMN IF EXISTS sizes_individuals,
                DROP COLUMN IF EXISTS sizes_companies, DROP COLUMN IF EXISTS one_size_individuals,
                DROP COLUMN IF EXISTS one_size_companies, DROP COLUMN IF EXISTS more_photos, DROP COLUMN IF EXISTS listed_at
            SQL);
        DB::statement('ALTER TABLE catalog.categories DROP COLUMN IF EXISTS description_ar, DROP COLUMN IF EXISTS description_en');
        DB::statement('ALTER TABLE catalog.products DROP CONSTRAINT IF EXISTS products_unit');
        DB::statement('ALTER TABLE catalog.products DROP COLUMN IF EXISTS unit');
    }
};
