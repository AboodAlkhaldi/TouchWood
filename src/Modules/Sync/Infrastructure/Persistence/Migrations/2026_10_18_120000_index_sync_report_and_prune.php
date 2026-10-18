<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Sync step 6 (the module's own pass): the report's page reads a store's open entries, the latest seen
| first - with or without one kind - from an index, not a sort of them all; the night's prune finds
| reads finished, and entries closed, over 90 days ago from an index, not a scan of the table.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX report_entries_open_seen ON sync.report_entries (store_id, seen_at DESC, id DESC) WHERE closed_at IS NULL');
        DB::statement('CREATE INDEX report_entries_open_kind_seen ON sync.report_entries (store_id, kind, seen_at DESC, id DESC) WHERE closed_at IS NULL');
        DB::statement('CREATE INDEX report_entries_closed ON sync.report_entries (closed_at) WHERE closed_at IS NOT NULL');
        DB::statement("CREATE INDEX reads_finished ON sync.reads (finished_at) WHERE state IN ('DONE', 'FAILED')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX sync.reads_finished');
        DB::statement('DROP INDEX sync.report_entries_closed');
        DB::statement('DROP INDEX sync.report_entries_open_kind_seen');
        DB::statement('DROP INDEX sync.report_entries_open_seen');
    }
};
