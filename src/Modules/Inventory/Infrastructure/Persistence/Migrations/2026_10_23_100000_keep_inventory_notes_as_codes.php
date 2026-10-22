<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Stage 5 step 10 (owner, 2026-10-20; inventory.md §1.6, §5): the notes Inventory writes itself - a
| shipment's shortfall and the unwiring's correction - are kept as a code and its values, and written in
| the page's language when shown, as Sync's own reasons are (sync.md Q31). A note typed by staff stays in
| `note`, as typed. Lines written before keep their English text in `note` and are shown as they are: the
| ledger is append-only, so nothing is turned into a code.
|
| A line holds one or the other, never both. A correction says why, and a shipment from a stock already at
| 0 names its shortfall, with either.
|
| Down puts the old checks back and drops the columns; PostgreSQL refuses the checks while a line with a
| code and no note is in the ledger, which is append-only, so a rollback after such a line is a decision,
| not a step.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE inventory.stock_movements ADD COLUMN note_code varchar(40) NULL, ADD COLUMN note_values jsonb NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE inventory.stock_movements
                ADD CONSTRAINT stock_movements_note_code CHECK (note_code IS NULL OR note_code IN ('SHIPPED_BEYOND_COUNT', 'UNWIRED')),
                ADD CONSTRAINT stock_movements_note_values CHECK (note_values IS NULL OR (note_code IS NOT NULL AND jsonb_typeof(note_values) = 'object')),
                ADD CONSTRAINT stock_movements_note_one CHECK (note IS NULL OR note_code IS NULL)
            SQL);

        DB::statement('ALTER TABLE inventory.stock_movements DROP CONSTRAINT stock_movements_note');
        DB::statement(<<<'SQL'
            ALTER TABLE inventory.stock_movements ADD CONSTRAINT stock_movements_note
                CHECK (reason <> 'CORRECTION' OR note_code IS NOT NULL OR (note IS NOT NULL AND btrim(note, E' \t\r\n\x0B') <> ''))
            SQL);

        DB::statement('ALTER TABLE inventory.stock_movements DROP CONSTRAINT stock_movements_direction');
        DB::statement(<<<'SQL'
            ALTER TABLE inventory.stock_movements ADD CONSTRAINT stock_movements_direction CHECK (
                CASE
                    WHEN reason IN ('RECEIVED', 'RETURNED') THEN change > 0
                    WHEN reason IN ('DAMAGED_LOST', 'OFFLINE_SALE') THEN change < 0
                    -- A shipment goes out; one from a stock already at 0 takes nothing and only notes
                    -- its shortfall: 0 -> 0, with its note - a code, or a line's text from before.
                    WHEN reason = 'SHIPPED' THEN change < 0 OR (change = 0 AND before = 0 AND (note_code IS NOT NULL OR (note IS NOT NULL AND btrim(note, E' \t\r\n\x0B') <> '')))
                    WHEN reason = 'CORRECTION' THEN change <> 0
                    -- A count, the file and the provider set a number; an unknown reason is
                    -- stock_movements_reason's to refuse.
                    ELSE true
                END
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inventory.stock_movements DROP CONSTRAINT stock_movements_direction');
        DB::statement(<<<'SQL'
            ALTER TABLE inventory.stock_movements ADD CONSTRAINT stock_movements_direction CHECK (
                CASE
                    WHEN reason IN ('RECEIVED', 'RETURNED') THEN change > 0
                    WHEN reason IN ('DAMAGED_LOST', 'OFFLINE_SALE') THEN change < 0
                    WHEN reason = 'SHIPPED' THEN change < 0 OR (change = 0 AND before = 0 AND note IS NOT NULL AND btrim(note, E' \t\r\n\x0B') <> '')
                    WHEN reason = 'CORRECTION' THEN change <> 0
                    ELSE true
                END
            )
            SQL);
        DB::statement('ALTER TABLE inventory.stock_movements DROP CONSTRAINT stock_movements_note');
        DB::statement(<<<'SQL'
            ALTER TABLE inventory.stock_movements ADD CONSTRAINT stock_movements_note
                CHECK (reason <> 'CORRECTION' OR (note IS NOT NULL AND btrim(note, E' \t\r\n\x0B') <> ''))
            SQL);
        DB::statement('ALTER TABLE inventory.stock_movements DROP CONSTRAINT stock_movements_note_one, DROP CONSTRAINT stock_movements_note_values, DROP CONSTRAINT stock_movements_note_code');
        DB::statement('ALTER TABLE inventory.stock_movements DROP COLUMN note_values, DROP COLUMN note_code');
    }
};
