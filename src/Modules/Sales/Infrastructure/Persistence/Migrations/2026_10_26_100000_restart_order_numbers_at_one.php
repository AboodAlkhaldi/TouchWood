<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Infrastructure\Eloquent\OrderNumberSequences;

/*
| sales.md §1.3, answer 35 as changed by the owner (2026-10-26): order numbers start at 1, shown SA-0001. A
| database made before kept the stores' sequences from 1001: each store with no order yet starts again at 1;
| a store with orders keeps going from where it is, so no number is given twice.
*/

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('platform.stores')->pluck('id') as $storeId) {
            $sequence = OrderNumberSequences::name((string) $storeId);

            if (DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS made', [$sequence])->made
                && ! DB::table('sales.orders')->where('store_id', $storeId)->exists()) {
                DB::statement(sprintf('ALTER SEQUENCE %s START WITH %d RESTART WITH %d', $sequence, OrderNumberSequences::FIRST, OrderNumberSequences::FIRST));
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: a number never placed is not a number.
    }
};
