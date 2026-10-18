<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Sync step 6b (sync.md §9.3 #28): a "discount skipped" entry closes the moment Pricing finds the
| discount reaching its size again - closed by `PRICING`.
|
| Undone, the entries Pricing closed read as closed by a read: the nearest of the old values.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sync.report_entries DROP CONSTRAINT report_entries_closed_by');
        DB::statement("ALTER TABLE sync.report_entries ADD CONSTRAINT report_entries_closed_by CHECK (closed_by IS NULL OR closed_by IN ('READ', 'UNWIRED', 'CATALOG', 'PRICING') OR closed_by ~ '^STAFF:[0-9a-z]{26}$')");
    }

    public function down(): void
    {
        DB::table('sync.report_entries')->where('closed_by', 'PRICING')->update(['closed_by' => 'READ']);
        DB::statement('ALTER TABLE sync.report_entries DROP CONSTRAINT report_entries_closed_by');
        DB::statement("ALTER TABLE sync.report_entries ADD CONSTRAINT report_entries_closed_by CHECK (closed_by IS NULL OR closed_by IN ('READ', 'UNWIRED', 'CATALOG') OR closed_by ~ '^STAFF:[0-9a-z]{26}$')");
    }
};
