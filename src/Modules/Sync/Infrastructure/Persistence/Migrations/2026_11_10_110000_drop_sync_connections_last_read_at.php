<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Odoo's own API (sync.md §2.4, owner 2026-11-09) cannot say what changed since a time: the hourly read
| reads everything, so `connections.last_read_at` - the time it asked from - has nothing left to say. The
| screens' Last Read is the newest read done (`sync.reads`), and Last Full Read keeps its own column.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sync.connections DROP COLUMN last_read_at');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sync.connections ADD COLUMN last_read_at timestamp(0) with time zone NULL');
    }
};
