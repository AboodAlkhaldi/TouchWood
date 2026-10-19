<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Stage 5's screens step 2 (owner Q43, 2026-10-19: Needs a Price's count kept per store, as Low Stock's
| is): how many sizes each store has switched on with no price, as the Needs a Price list counts them, so
| the menu's count is one query. Counted again by a job after every change that can move it; a store not
| counted yet is counted live once. `recount_queued` keeps one job waiting per store: set by whoever
| queues it first, cleared as it starts.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing.unpriced_counts', function (Blueprint $table): void {
            $table->ulid('store_id')->primary();
            $table->integer('unpriced')->nullable();
            $table->timestampTz('counted_at')->nullable();
            $table->boolean('recount_queued')->default(false);

            $table->foreign('store_id', 'unpriced_counts_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE pricing.unpriced_counts ADD CONSTRAINT unpriced_counts_unpriced CHECK (unpriced >= 0), ADD CONSTRAINT unpriced_counts_counted CHECK ((unpriced IS NULL) = (counted_at IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing.unpriced_counts');
    }
};
