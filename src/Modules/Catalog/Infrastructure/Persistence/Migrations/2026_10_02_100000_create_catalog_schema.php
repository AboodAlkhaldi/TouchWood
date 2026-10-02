<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| catalog.md §5. The schema is also on config/database.php's search_path, so migrate:fresh wipes it.
|
| pg_trgm serves the search's "nearest to what they typed" (handoff §9.5, catalog.md §1.11). It is a
| trusted extension, so the database's owner may create it; it lives in the public schema, which
| every connection already has on its path.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS catalog');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    public function down(): void
    {
        // The extension stays: it belongs to the database, not to this schema.
        DB::statement('DROP SCHEMA IF EXISTS catalog CASCADE');
    }
};
