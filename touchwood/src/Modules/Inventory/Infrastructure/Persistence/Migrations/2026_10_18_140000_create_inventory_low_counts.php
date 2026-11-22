<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Inventory step 5 (owner, 2026-10-18: a count kept per store): how many sizes each store has low, as
| the Low Stock list counts them, so the menu's count is one query. Counted again by a job after every
| change that can move it; a store not counted yet is counted live once. `recount_queued` keeps one job
| waiting per store: set by whoever queues it first, cleared as it starts.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory.low_counts', function (Blueprint $table): void {
            $table->ulid('store_id')->primary();
            $table->integer('low')->nullable();
            $table->timestampTz('counted_at')->nullable();
            $table->boolean('recount_queued')->default(false);

            $table->foreign('store_id', 'low_counts_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE inventory.low_counts ADD CONSTRAINT low_counts_low CHECK (low >= 0), ADD CONSTRAINT low_counts_counted CHECK ((low IS NULL) = (counted_at IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory.low_counts');
    }
};
