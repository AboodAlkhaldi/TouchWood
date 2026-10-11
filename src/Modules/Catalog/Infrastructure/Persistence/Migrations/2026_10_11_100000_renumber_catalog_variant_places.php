<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| catalog.md §1.2, amendment 16(d): a variant's place is no longer typed - it is dragged, a variant
| added going last. The places typed before (0 for most, any number to 10,000) are written again as
| 1, 2, 3 … in each product's order as it reads now - by place, then id -, so no two variants share
| one and none sits where "last" would pass the 10,000 the column takes (the review of #120). The
| order shown does not change; the old numbers are not kept, so `down()` leaves them.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE catalog.variants v
            SET position = r.place
            FROM (SELECT id, row_number() OVER (PARTITION BY product_id ORDER BY position, id) AS place FROM catalog.variants) r
            WHERE v.id = r.id AND v.position <> r.place
            SQL);
    }

    public function down(): void
    {
        // The order is kept; the numbers typed before are not.
    }
};
