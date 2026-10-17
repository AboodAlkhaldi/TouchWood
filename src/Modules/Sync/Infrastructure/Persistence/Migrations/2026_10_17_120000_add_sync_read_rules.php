<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Sync step 4a (sync.md §1.3, §1.6, §9.3 #17): the codes a notified or one-code read asks for; one read
| of a store running at a time, and one full read of each kind waiting - asking twice queues one more,
| never two - as unique indexes, so two workers can never both win; the report's eighth kind, a
| discount skipping a size.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE sync.reads
                ADD COLUMN codes jsonb NULL,
                ADD CONSTRAINT reads_codes CHECK (
                    (kind IN ('NOTIFIED', 'CODE')) = (codes IS NOT NULL)
                    AND (codes IS NULL OR jsonb_typeof(codes) = 'array')
                )
            SQL);
        DB::statement("CREATE UNIQUE INDEX reads_one_running ON sync.reads (store_id) WHERE state = 'RUNNING'");
        DB::statement("CREATE UNIQUE INDEX reads_one_waiting ON sync.reads (store_id, kind) WHERE state = 'QUEUED' AND kind IN ('NOW', 'HOURLY', 'NIGHTLY')");

        DB::statement(<<<'SQL'
            ALTER TABLE sync.report_entries
                DROP CONSTRAINT report_entries_kind,
                ADD CONSTRAINT report_entries_kind CHECK (kind IN ('UNKNOWN_CODE', 'NOT_IN_PROVIDER', 'ARCHIVED_SKIPPED', 'OFF_OR_DRAFT_PRICED', 'SALE_STOPPED', 'BAD_VALUE', 'READ_FAILED', 'DISCOUNT_SKIPPED'))
            SQL);
    }

    public function down(): void
    {
        DB::statement("DELETE FROM sync.report_entries WHERE kind = 'DISCOUNT_SKIPPED'");
        DB::statement(<<<'SQL'
            ALTER TABLE sync.report_entries
                DROP CONSTRAINT report_entries_kind,
                ADD CONSTRAINT report_entries_kind CHECK (kind IN ('UNKNOWN_CODE', 'NOT_IN_PROVIDER', 'ARCHIVED_SKIPPED', 'OFF_OR_DRAFT_PRICED', 'SALE_STOPPED', 'BAD_VALUE', 'READ_FAILED'))
            SQL);
        DB::statement('DROP INDEX IF EXISTS sync.reads_one_waiting');
        DB::statement('DROP INDEX IF EXISTS sync.reads_one_running');
        DB::statement('ALTER TABLE sync.reads DROP CONSTRAINT reads_codes, DROP COLUMN codes');
    }
};
