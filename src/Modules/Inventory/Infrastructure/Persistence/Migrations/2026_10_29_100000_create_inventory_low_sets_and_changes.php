<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| inventory.md amendment 1 (Ops, 2026-10-28): each store's low set as its last recount found it - the
| sizes low (1 or more free) and out (none free) - so the next recount can say what changed; and the
| change log the daily report reads (`InventoryApi::lowStockChanges`), kept 12 months. A store with no
| set has not been recounted since this was deployed: its next recount keeps the set silently (§1.7).
| Both times keep microseconds: the log's moments are compared with the report's range.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory.low_sets', function (Blueprint $table): void {
            $table->ulid('store_id')->primary();
            $table->jsonb('low');
            $table->jsonb('out');
            $table->timestampTz('counted_at', 6);

            $table->foreign('store_id', 'low_sets_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE inventory.low_sets ADD CONSTRAINT low_sets_lists CHECK (jsonb_typeof(low) = 'array' AND jsonb_typeof(out) = 'array')");

        Schema::create('inventory.low_changes', function (Blueprint $table): void {
            // bigint identity (handoff §5.3), as the ledger.
            $table->id()->generatedAs()->always();
            $table->ulid('store_id');
            // No key, as the ledger's: a deleted size keeps its history.
            $table->ulid('variant_id');
            $table->string('change', 4);
            $table->timestampTz('changed_at', 6);

            $table->foreign('store_id', 'low_changes_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->index(['store_id', 'changed_at'], 'low_changes_store_time_idx');
            $table->index('changed_at', 'low_changes_time_idx');
        });

        DB::statement("ALTER TABLE inventory.low_changes ADD CONSTRAINT low_changes_change CHECK (change IN ('LOW', 'OUT', 'BACK'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory.low_changes');
        Schema::dropIfExists('inventory.low_sets');
    }
};
