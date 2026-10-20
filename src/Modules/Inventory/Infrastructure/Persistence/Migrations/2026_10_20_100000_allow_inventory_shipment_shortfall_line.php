<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| The owner, 2026-10-19 (inventory.md §2.1 `ship`, §9.2 #22; sales.md answer 41): a shipment's shortfall
| is always recorded for a recount - from a stock already at 0 too, where nothing is taken. So a SHIPPED
| line may change nothing, but only 0 -> 0 and only with its note (not blank, as a correction's note is
| read); any other is refused as before. Every other reason keeps its one way.
|
| Down puts the old check back; PostgreSQL refuses it while a 0 -> 0 shipped line is in the ledger, which
| is append-only, so a rollback after such a line is a decision, not a step.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE inventory.stock_movements DROP CONSTRAINT stock_movements_direction');
        // NOT VALID, then VALIDATE: the check is added without reading the ledger, which is then read
        // under a lighter lock (SHARE UPDATE EXCLUSIVE) - though inside the migration's own transaction the
        // DROP's lock is held until it commits.
        DB::statement(<<<'SQL'
            ALTER TABLE inventory.stock_movements ADD CONSTRAINT stock_movements_direction CHECK (
                CASE
                    WHEN reason IN ('RECEIVED', 'RETURNED') THEN change > 0
                    WHEN reason IN ('DAMAGED_LOST', 'OFFLINE_SALE') THEN change < 0
                    -- A shipment goes out; one from a stock already at 0 takes nothing and only notes
                    -- its shortfall: 0 -> 0, with its note.
                    WHEN reason = 'SHIPPED' THEN change < 0 OR (change = 0 AND before = 0 AND note IS NOT NULL AND btrim(note, E' \t\r\n\x0B') <> '')
                    WHEN reason = 'CORRECTION' THEN change <> 0
                    -- A count, the file and the provider set a number; an unknown reason is
                    -- stock_movements_reason's to refuse.
                    ELSE true
                END
            ) NOT VALID
            SQL);
        DB::statement('ALTER TABLE inventory.stock_movements VALIDATE CONSTRAINT stock_movements_direction');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inventory.stock_movements DROP CONSTRAINT stock_movements_direction');
        DB::statement(<<<'SQL'
            ALTER TABLE inventory.stock_movements ADD CONSTRAINT stock_movements_direction CHECK (
                CASE
                    WHEN reason IN ('RECEIVED', 'RETURNED') THEN change > 0
                    WHEN reason IN ('DAMAGED_LOST', 'OFFLINE_SALE', 'SHIPPED') THEN change < 0
                    WHEN reason = 'CORRECTION' THEN change <> 0
                    -- A count, the file and the provider set a number; an unknown reason is
                    -- stock_movements_reason's to refuse.
                    ELSE true
                END
            )
            SQL);
    }
};
