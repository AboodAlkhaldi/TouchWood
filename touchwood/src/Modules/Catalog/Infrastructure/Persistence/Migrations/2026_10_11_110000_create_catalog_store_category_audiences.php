<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| catalog.md §1.13, §5.2, §5.4 (amendment 14): who sees a category in each store, and what the listing
| keeps of it.
|
| `store_category_audiences` holds a store's two switches of a category, Individuals and Companies.
| **No row means both** — a category nobody switched is seen by both (14(b)) — so a new category and a
| store opened later need no rows.
|
| The listing's rows gain, for each store and product: whether individuals and companies see it (its
| own category's switches; for individuals, a variant sold retail too — 14(e)); whether any variant
| there sells retail, or wholesale; and each side's card variant — the first, in the product's own
| order, that side can buy (14(f)): any for companies and guests, a retail one for individuals. No
| foreign key on the variants: the rows are a read model the writer rebuilds, and a listed product's
| variants are archived, never deleted.
|
| The rows already there are given the new columns from the stores' own rows, as the writer gives
| them (DatabaseListingRows): nobody has switched anything yet, so companies see every row and
| individuals every row with a variant sold retail. A row with no variant on sale — which the writer
| never leaves, and the repair would remove — is removed, so the card variant can be required.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog.store_category_audiences', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->ulid('category_id');
            $table->boolean('for_individuals');
            $table->boolean('for_companies');
            $table->timestampsTz();

            $table->primary(['store_id', 'category_id'], 'store_category_audiences_pkey');
            $table->foreign('store_id', 'store_category_audiences_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('category_id', 'store_category_audiences_category')->references('id')->on('catalog.categories')->cascadeOnDelete();
            $table->index('category_id', 'store_category_audiences_category_idx');
        });

        DB::statement('ALTER TABLE catalog.listing ADD COLUMN for_individuals boolean NOT NULL DEFAULT true');
        DB::statement('ALTER TABLE catalog.listing ADD COLUMN for_companies boolean NOT NULL DEFAULT true');
        DB::statement('ALTER TABLE catalog.listing ADD COLUMN sells_retail boolean NOT NULL DEFAULT true');
        DB::statement('ALTER TABLE catalog.listing ADD COLUMN sells_wholesale boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE catalog.listing ADD COLUMN card_variant_id char(26) NULL');
        DB::statement('ALTER TABLE catalog.listing ADD COLUMN retail_card_variant_id char(26) NULL');

        DB::statement(
            'UPDATE catalog.listing l SET sells_retail = s.retail, sells_wholesale = s.wholesale, for_individuals = s.retail,'
            .' card_variant_id = s.first_any, retail_card_variant_id = s.first_retail'
            .' FROM (SELECT sv.store_id, sv.product_id, bool_or(sv.sells_retail) AS retail, bool_or(sv.sells_wholesale) AS wholesale,'
            .' (array_agg(sv.variant_id ORDER BY v.position, v.id))[1] AS first_any,'
            .' (array_agg(sv.variant_id ORDER BY v.position, v.id) FILTER (WHERE sv.sells_retail))[1] AS first_retail'
            .' FROM catalog.store_variants sv'
            .' JOIN catalog.store_products sp ON sp.store_id = sv.store_id AND sp.product_id = sv.product_id'
            .' JOIN catalog.variants v ON v.id = sv.variant_id'
            .' WHERE sv.is_active AND NOT sv.not_available_now AND NOT sp.not_available_now AND NOT v.is_archived'
            .' GROUP BY sv.store_id, sv.product_id) s'
            .' WHERE s.store_id = l.store_id AND s.product_id = l.product_id',
        );

        DB::statement('DELETE FROM catalog.listing WHERE card_variant_id IS NULL');
        DB::statement('ALTER TABLE catalog.listing ALTER COLUMN card_variant_id SET NOT NULL');
        DB::statement('ALTER TABLE catalog.listing ALTER COLUMN for_individuals DROP DEFAULT');
        DB::statement('ALTER TABLE catalog.listing ALTER COLUMN for_companies DROP DEFAULT');
        DB::statement('ALTER TABLE catalog.listing ALTER COLUMN sells_retail DROP DEFAULT');
        DB::statement('ALTER TABLE catalog.listing ALTER COLUMN sells_wholesale DROP DEFAULT');

        // Individuals buy retail only (14(e)): they see a row only with a variant sold retail, and its
        // card shows one. Each the writer's rule first (DatabaseListingRows), the database's behind it.
        DB::statement('ALTER TABLE catalog.listing ADD CONSTRAINT listing_individuals_retail CHECK (NOT for_individuals OR sells_retail)');
        DB::statement('ALTER TABLE catalog.listing ADD CONSTRAINT listing_retail_card CHECK ((retail_card_variant_id IS NOT NULL) = sells_retail)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE catalog.listing DROP CONSTRAINT IF EXISTS listing_retail_card');
        DB::statement('ALTER TABLE catalog.listing DROP CONSTRAINT IF EXISTS listing_individuals_retail');

        foreach (['retail_card_variant_id', 'card_variant_id', 'sells_wholesale', 'sells_retail', 'for_companies', 'for_individuals'] as $column) {
            DB::statement("ALTER TABLE catalog.listing DROP COLUMN IF EXISTS {$column}");
        }

        Schema::dropIfExists('catalog.store_category_audiences');
    }
};
