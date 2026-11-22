<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| catalog.md §4.4, amendment 16(d), P29: the brands', labels', attributes' and each attribute's
| values' places are no longer typed - they are dragged, a new item going last. The places typed
| before (often 0 for every item, any number to 10,000) are written again as 1, 2, 3 … in each
| list's order as it shows now - by place, then English name, then id -, so no two items share one
| and none sits where "last" would pass the 10,000 the column takes (the review of #122). The
| warranties were numbered so when they gained their order (2026_10_11_110000). The order shown
| does not change; the old numbers are not kept, so `down()` leaves them.
*/

return new class extends Migration
{
    /** Each list's table, and the column a list is one of several by: an attribute's values. */
    private const array LISTS = [
        'catalog.brands' => null,
        'catalog.labels' => null,
        'catalog.attributes' => null,
        'catalog.attribute_values' => 'attribute_id',
    ];

    public function up(): void
    {
        foreach (self::LISTS as $table => $partition) {
            $over = ($partition === null ? '' : "PARTITION BY {$partition} ").'ORDER BY position, name_en, id';

            DB::statement(<<<SQL
                UPDATE {$table} t
                SET position = r.place
                FROM (SELECT id, row_number() OVER ({$over}) AS place FROM {$table}) r
                WHERE t.id = r.id AND t.position <> r.place
                SQL);
        }
    }

    public function down(): void
    {
        // The order is kept; the numbers typed before are not.
    }
};
