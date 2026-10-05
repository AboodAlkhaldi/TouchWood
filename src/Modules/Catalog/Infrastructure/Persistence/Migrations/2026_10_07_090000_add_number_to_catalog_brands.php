<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| catalog.md §1.6, §5.3 (amendment 7(b)): every brand has a fixed number — 1, 2, 3 … given when it is
| added, never changed, never given to another brand, even after a delete — so a products file names
| a brand by its number. The brands already there are numbered in the order they were added; an
| identity column numbers the next ones, and refuses a number written by hand.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE catalog.brands ADD COLUMN number integer');
        DB::statement('UPDATE catalog.brands AS b SET number = r.n FROM (SELECT id, row_number() OVER (ORDER BY created_at, id) AS n FROM catalog.brands) AS r WHERE b.id = r.id');
        DB::statement('ALTER TABLE catalog.brands ALTER COLUMN number SET NOT NULL');
        DB::statement('ALTER TABLE catalog.brands ALTER COLUMN number ADD GENERATED ALWAYS AS IDENTITY');
        DB::statement("SELECT setval(pg_get_serial_sequence('catalog.brands', 'number'), COALESCE((SELECT max(number) FROM catalog.brands), 0) + 1, false)");
        DB::statement('ALTER TABLE catalog.brands ADD CONSTRAINT brands_number_unique UNIQUE (number)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE catalog.brands DROP COLUMN number');
    }
};
