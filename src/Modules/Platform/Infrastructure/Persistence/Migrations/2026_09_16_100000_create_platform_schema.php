<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Each module owns a PostgreSQL schema (handoff §4.3). The schema must also be listed in the
| pgsql connection's search_path (config/database.php) so migrate:fresh can wipe it.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS platform');
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS platform CASCADE');
    }
};
