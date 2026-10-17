<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Sync step 5b (sync.md §9.3 #18): an entry staff are told of is told once - `announced_at` set when a
| read ends and tells Ops of it. The entries open before this step were never told; they stay quiet.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sync.report_entries ADD COLUMN announced_at timestamptz NULL');
        DB::statement('UPDATE sync.report_entries SET announced_at = opened_at');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sync.report_entries DROP COLUMN announced_at');
    }
};
