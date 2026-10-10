<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| catalog.md §1.9, §5.3, amendment 16(d): the warranties gain an order, set by dragging — the order of
| the product form's choice — as every other ordered list of Catalog's. The warranties already there
| are numbered 1, 2, 3 … in name order, the order the list showed them in until now (English name,
| then id); a new one goes last (the handler, under the warranties' lock). The range is the other
| lists' (ListPosition), and the code refuses first.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE catalog.warranties ADD COLUMN position integer');
        DB::statement('UPDATE catalog.warranties AS w SET position = r.n FROM (SELECT id, row_number() OVER (ORDER BY name_en, id) AS n FROM catalog.warranties) AS r WHERE w.id = r.id');
        DB::statement('ALTER TABLE catalog.warranties ALTER COLUMN position SET NOT NULL');
        DB::statement('ALTER TABLE catalog.warranties ADD CONSTRAINT warranties_position_range CHECK (position BETWEEN 0 AND 10000)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE catalog.warranties DROP COLUMN position');
    }
};
