<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Access spec §5. The schema is also on config/database.php's search_path, so migrate:fresh
| wipes it.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS access');
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS access CASCADE');
    }
};
