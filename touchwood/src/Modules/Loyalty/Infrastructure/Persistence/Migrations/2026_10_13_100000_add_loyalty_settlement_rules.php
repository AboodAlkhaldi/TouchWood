<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Four rules the step 2 reviews found, added with the settlements that write those rows (owner,
| 2026-10-11, loyalty.md §9.1 answer 19):
| - an EXPIRED entry that names a return names its order;
| - every cover names the return that made it — only a return takes earned points back (§1.8);
| - expired given-back points kept for later never pass the points given back that did not go
|   through covers: they are the order's own, landed on the lots it took them from;
| - an entry's return is a settlement of that order (`order_returns`) — unchecked while it names none.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE loyalty.entries
                ADD CONSTRAINT entries_expired_return_order CHECK (kind <> 'EXPIRED' OR return_id IS NULL OR order_id IS NOT NULL),
                ADD CONSTRAINT entries_settlement FOREIGN KEY (order_id, return_id) REFERENCES loyalty.order_returns (order_id, return_id) ON DELETE RESTRICT
            SQL);
        DB::statement('ALTER TABLE loyalty.covers ALTER COLUMN return_id SET NOT NULL');
        DB::statement('ALTER TABLE loyalty.orders ADD CONSTRAINT orders_expired_back CHECK (expired_back_unused <= points_given_back - points_back_through_covers)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE loyalty.orders DROP CONSTRAINT orders_expired_back');
        DB::statement('ALTER TABLE loyalty.covers ALTER COLUMN return_id DROP NOT NULL');
        DB::statement('ALTER TABLE loyalty.entries DROP CONSTRAINT entries_settlement, DROP CONSTRAINT entries_expired_return_order');
    }
};
