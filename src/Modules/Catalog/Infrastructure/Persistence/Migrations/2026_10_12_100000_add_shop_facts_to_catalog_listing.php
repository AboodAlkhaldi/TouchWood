<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| catalog.md §5.4 (amendments 15, 16(h); P22): what the listing keeps of the facts pushed into
| `store_variant_facts`, for the shop's cards.
|
| - **"Last pieces", per side, as the prices**: some size here that side can buy and order now is
|   ending soon — any size for companies (and guests, who share their card), a size sold retail for
|   individuals; a size not orderable lights neither.
| - **Each side's card price**: the price and the price before of the variant its card shows —
|   companies' and guests' in `price_minor`/`price_before_minor`, individuals' in the `retail_` pair.
|   Kept, not shown, until Pricing's stage brings prices to the shop.
|
| The rows already there are given them from the facts already kept, as the writer gives them
| (DatabaseListingRows).
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE catalog.listing ADD COLUMN ending_soon_companies boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE catalog.listing ADD COLUMN ending_soon_individuals boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE catalog.listing ADD COLUMN price_before_minor bigint NULL');
        DB::statement('ALTER TABLE catalog.listing ADD COLUMN retail_price_minor bigint NULL');
        DB::statement('ALTER TABLE catalog.listing ADD COLUMN retail_price_before_minor bigint NULL');

        $ending = 'EXISTS (SELECT 1 FROM catalog.store_variants sv'
            .' JOIN catalog.store_products sp ON sp.store_id = sv.store_id AND sp.product_id = sv.product_id'
            .' JOIN catalog.variants v ON v.id = sv.variant_id'
            .' JOIN catalog.store_variant_facts f ON f.store_id = sv.store_id AND f.variant_id = sv.variant_id'
            .' WHERE sv.store_id = l.store_id AND sv.product_id = l.product_id AND sv.is_active AND NOT sv.not_available_now'
            .' AND NOT sp.not_available_now AND NOT v.is_archived AND f.ending_soon AND f.orderable IS NOT FALSE%s)';
        $fact = static fn (string $column, string $variant): string => "(SELECT f.{$column} FROM catalog.store_variant_facts f WHERE f.store_id = l.store_id AND f.variant_id = l.{$variant})";

        DB::statement(
            'UPDATE catalog.listing l SET'
            .' ending_soon_companies = '.sprintf($ending, '')
            .', ending_soon_individuals = '.sprintf($ending, ' AND sv.sells_retail')
            .', price_minor = '.$fact('price_minor', 'card_variant_id')
            .', price_before_minor = '.$fact('price_before_minor', 'card_variant_id')
            .', retail_price_minor = '.$fact('price_minor', 'retail_card_variant_id')
            .', retail_price_before_minor = '.$fact('price_before_minor', 'retail_card_variant_id'),
        );
    }

    public function down(): void
    {
        foreach (['retail_price_before_minor', 'retail_price_minor', 'price_before_minor', 'ending_soon_individuals', 'ending_soon_companies'] as $column) {
            DB::statement("ALTER TABLE catalog.listing DROP COLUMN IF EXISTS {$column}");
        }
    }
};
