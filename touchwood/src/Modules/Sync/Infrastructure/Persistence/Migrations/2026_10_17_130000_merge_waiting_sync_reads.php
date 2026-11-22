<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Sync step 4b (sync.md §1.3: "asking twice queues one more read, never two"): one waiting read of each
| kind per store - a notice's and a corrected code's too, whose codes are merged into the one waiting -
| so a busy provider never piles reads up ahead of the hour's and the night's.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX sync.reads_one_waiting');
        DB::statement("CREATE UNIQUE INDEX reads_one_waiting ON sync.reads (store_id, kind) WHERE state = 'QUEUED'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX sync.reads_one_waiting');
        DB::statement("CREATE UNIQUE INDEX reads_one_waiting ON sync.reads (store_id, kind) WHERE state = 'QUEUED' AND kind IN ('NOW', 'HOURLY', 'NIGHTLY')");
    }
};
