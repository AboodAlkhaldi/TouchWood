<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| catalog.md §1.6, §5.5 (amendments 7(b), 10(a)): every brand has a fixed number — 1, 2, 3 … — so a
| products file names a brand by its number. The brands already there are numbered in the order they
| were added; a new brand takes the lowest number no brand holds (the repository, under the brands'
| lock), so a deleted brand's number is free again and a failed add leaves no gap. A brand's own
| number never changes while it exists: a trigger refuses it.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE catalog.brands ADD COLUMN number integer');
        DB::statement('UPDATE catalog.brands AS b SET number = r.n FROM (SELECT id, row_number() OVER (ORDER BY created_at, id) AS n FROM catalog.brands) AS r WHERE b.id = r.id');
        DB::statement('ALTER TABLE catalog.brands ALTER COLUMN number SET NOT NULL');
        DB::statement('ALTER TABLE catalog.brands ADD CONSTRAINT brands_number_unique UNIQUE (number)');
        DB::statement('ALTER TABLE catalog.brands ADD CONSTRAINT brands_number_positive CHECK (number > 0)');

        // OR REPLACE: migrate:fresh drops tables but not functions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION catalog.brands_number_is_fixed() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'catalog.brands.number is fixed: a brand keeps its number while it exists';
            END;
            $$;

            CREATE TRIGGER brands_number_fixed
                BEFORE UPDATE OF number ON catalog.brands
                FOR EACH ROW WHEN (NEW.number IS DISTINCT FROM OLD.number)
                EXECUTE FUNCTION catalog.brands_number_is_fixed();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS brands_number_fixed ON catalog.brands');
        DB::unprepared('DROP FUNCTION IF EXISTS catalog.brands_number_is_fixed()');
        DB::statement('ALTER TABLE catalog.brands DROP COLUMN number');
    }
};
