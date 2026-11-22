<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| sync.md §9.5 amendment 2 (d): a new report kind, `CAMPAIGN_SKIPPED` - the provider's price made a
| campaign run skip a size (pricing.md §9.6 #5); the entry keeps the run's id in `detail`.
|
| Undone, its entries go: no older kind says what they said.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE sync.report_entries
                DROP CONSTRAINT report_entries_kind,
                ADD CONSTRAINT report_entries_kind CHECK (kind IN ('UNKNOWN_CODE', 'NOT_IN_PROVIDER', 'ARCHIVED_SKIPPED', 'OFF_OR_DRAFT_PRICED', 'SALE_STOPPED', 'BAD_VALUE', 'READ_FAILED', 'DISCOUNT_SKIPPED', 'CAMPAIGN_SKIPPED'))
            SQL);
    }

    public function down(): void
    {
        DB::statement("DELETE FROM sync.report_entries WHERE kind = 'CAMPAIGN_SKIPPED'");
        DB::statement(<<<'SQL'
            ALTER TABLE sync.report_entries
                DROP CONSTRAINT report_entries_kind,
                ADD CONSTRAINT report_entries_kind CHECK (kind IN ('UNKNOWN_CODE', 'NOT_IN_PROVIDER', 'ARCHIVED_SKIPPED', 'OFF_OR_DRAFT_PRICED', 'SALE_STOPPED', 'BAD_VALUE', 'READ_FAILED', 'DISCOUNT_SKIPPED'))
            SQL);
    }
};
