<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| catalog.md §5.4 (amendment 35; the shop's screens' B8): the prices the shop's cards show.
|
| - **`store_variant_facts.wholesale_from_minor`**: pushed by Pricing with each price (`ListingPrice`), the
|   least one piece costs now on a wholesale line. Empty until Pricing pushes again: its repair
|   (`RebuildPriceCandidates`) pushes every price once, after this is deployed.
| - **`listing.retail_from_minor`**: the lowest price now among the product's retail sizes there, only when
|   they differ — the card's "From". The rows already there are given it from the facts already kept, as
|   the writer gives it (DatabaseListingRows).
| - **`listing.wholesale_from_minor`**: the least of its wholesale sizes' — the card's "Wholesale from".
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE catalog.store_variant_facts ADD COLUMN wholesale_from_minor bigint NULL');
        DB::statement('ALTER TABLE catalog.store_variant_facts ADD CONSTRAINT store_variant_facts_wholesale_from_with_now CHECK (wholesale_from_minor IS NULL OR (price_minor IS NOT NULL AND wholesale_from_minor >= 0))');
        DB::statement('ALTER TABLE catalog.listing ADD COLUMN retail_from_minor bigint NULL');
        DB::statement('ALTER TABLE catalog.listing ADD COLUMN wholesale_from_minor bigint NULL');

        DB::statement(
            'UPDATE catalog.listing l SET retail_from_minor = r.lowest FROM ('
            .'SELECT sv.store_id, sv.product_id, CASE WHEN count(DISTINCT f.price_minor) > 1 THEN min(f.price_minor) END AS lowest'
            .' FROM catalog.store_variants sv'
            .' JOIN catalog.store_products sp ON sp.store_id = sv.store_id AND sp.product_id = sv.product_id'
            .' JOIN catalog.variants v ON v.id = sv.variant_id'
            .' JOIN catalog.store_variant_facts f ON f.store_id = sv.store_id AND f.variant_id = sv.variant_id'
            .' WHERE sv.is_active AND sv.sells_retail AND NOT sv.not_available_now AND NOT sp.not_available_now AND NOT v.is_archived'
            .' GROUP BY sv.store_id, sv.product_id) r'
            .' WHERE l.store_id = r.store_id AND l.product_id = r.product_id',
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE catalog.listing DROP COLUMN IF EXISTS wholesale_from_minor');
        DB::statement('ALTER TABLE catalog.listing DROP COLUMN IF EXISTS retail_from_minor');
        DB::statement('ALTER TABLE catalog.store_variant_facts DROP CONSTRAINT IF EXISTS store_variant_facts_wholesale_from_with_now');
        DB::statement('ALTER TABLE catalog.store_variant_facts DROP COLUMN IF EXISTS wholesale_from_minor');
    }
};
