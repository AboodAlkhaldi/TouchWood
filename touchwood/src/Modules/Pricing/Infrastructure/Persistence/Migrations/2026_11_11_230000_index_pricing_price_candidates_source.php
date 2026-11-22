<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Content's list of campaigns (content.md W4's Offers; §9.6 amendment row): `CampaignPrices::reachedSizes` counts
| each listed run's sizes from its CAMPAIGN candidates by `source_id`, which had no index - every list read the whole
| table (PR 8's second review). A plain index from the schema builder (CONVENTIONS: no partial index without the
| owner's OK): the source first, then the kind and the size, so the count is read from the index alone.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing.price_candidates', function (Blueprint $table): void {
            $table->index(['source_id', 'kind', 'variant_id'], 'price_candidates_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pricing.price_candidates', function (Blueprint $table): void {
            $table->dropIndex('price_candidates_source_idx');
        });
    }
};
