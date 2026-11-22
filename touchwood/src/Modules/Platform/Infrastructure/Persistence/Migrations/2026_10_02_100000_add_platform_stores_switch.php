<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Platform spec §5.2 (owner, 2026-10-01 and 2026-10-02): a store is on or off, and one store is the
| base store, which is always on.
|
| - `is_active`: a new store is created **off**. The stores that exist when the column is added are
|   live today, so they are turned **on** here.
| - `is_base`: one store at most carries the mark (`stores_one_base`), and it is always on
|   (`stores_base_always_active`). KSA is marked here, by its code: infrastructure may name a store,
|   `Domain/` and `Application/` never do (handoff §2 rule 2). The seed marks it too, for an
|   installation whose stores are created after the migrations.
|
| Both rules are checked in code first (`Store`), so the database never receives a row it would
| refuse (§5.8).
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE platform.stores
                ADD COLUMN is_active boolean NOT NULL DEFAULT false,
                ADD COLUMN is_base boolean NOT NULL DEFAULT false
            SQL);

        DB::statement('UPDATE platform.stores SET is_active = true');
        DB::statement("UPDATE platform.stores SET is_base = true WHERE code = 'sa'");

        DB::statement('CREATE UNIQUE INDEX stores_one_base ON platform.stores (is_base) WHERE is_base');
        DB::statement(<<<'SQL'
            ALTER TABLE platform.stores
                ADD CONSTRAINT stores_base_always_active CHECK (NOT is_base OR is_active)
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE platform.stores DROP CONSTRAINT IF EXISTS stores_base_always_active');
        DB::statement('DROP INDEX IF EXISTS platform.stores_one_base');
        DB::statement('ALTER TABLE platform.stores DROP COLUMN IF EXISTS is_base, DROP COLUMN IF EXISTS is_active');
    }
};
