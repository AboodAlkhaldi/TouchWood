<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| catalog.md amendment 43 (owner, 2026-11-11): a wholesale price on sale shows its old price struck through.
|
| - **`store_variant_facts.wholesale_from_before_minor`**: pushed by Pricing with each price (`ListingPrice`), the
|   least a piece would cost on a wholesale line with no sale, discount or campaign, only while
|   `wholesale_from_minor` is lower. Empty until Pricing pushes again: its repair (`pricing:candidates:rebuild`)
|   pushes every price once after this is deployed, and each push writes the products' listing rows again.
| - **`listing.wholesale_from_before_minor`**: the card's — the least of its wholesale sizes' normal prices, only
|   while the card's "Wholesale from" is lower. Empty in every row until that push, so nothing is struck meanwhile.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE catalog.store_variant_facts ADD COLUMN wholesale_from_before_minor bigint NULL');
        DB::statement('ALTER TABLE catalog.store_variant_facts ADD CONSTRAINT store_variant_facts_wholesale_before_above CHECK (wholesale_from_before_minor IS NULL OR (wholesale_from_minor IS NOT NULL AND wholesale_from_before_minor > wholesale_from_minor))');
        DB::statement('ALTER TABLE catalog.listing ADD COLUMN wholesale_from_before_minor bigint NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE catalog.listing DROP COLUMN IF EXISTS wholesale_from_before_minor');
        DB::statement('ALTER TABLE catalog.store_variant_facts DROP CONSTRAINT IF EXISTS store_variant_facts_wholesale_before_above');
        DB::statement('ALTER TABLE catalog.store_variant_facts DROP COLUMN IF EXISTS wholesale_from_before_minor');
    }
};
