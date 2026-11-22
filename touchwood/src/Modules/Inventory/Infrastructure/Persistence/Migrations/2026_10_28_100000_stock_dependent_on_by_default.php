<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| The stock-dependent switch is on by default (owner, 2026-10-28; inventory.md §9.2 #24): in a store wired
| to a provider every size depends on stock - every size there already, not only new ones - and staff may
| switch a size off. A size with no row counts as on in the code (`StockFigures::dependent`); a row made
| from now on is on by the column's default; every row already kept is set on here.
|
| No audit entry: nothing a person did changes, as the repair audits nothing (inventory.md §3). Catalog's
| orderable and "last pieces" facts, and each store's kept Low Stock count, are told again by
| `php artisan inventory:orderable:rebuild` right after this migration (the deploy step, HANDOFF.md §15.4),
| as the stage 5 deploy did: a migration does not run the module's code.
|
| Down puts the old default (off) back. It cannot tell which rows were off before up: those are all on
| after it, and a size with no row counts as off again once the code before this change runs.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE inventory.stock ALTER COLUMN stock_dependent SET DEFAULT true');
        DB::statement('UPDATE inventory.stock SET stock_dependent = true, updated_at = now() WHERE stock_dependent = false');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inventory.stock ALTER COLUMN stock_dependent SET DEFAULT false');
    }
};
