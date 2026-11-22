<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Sync\Infrastructure\Persistence\SyncSchema;

/*
| sync.md §5. The schema is also on config/database.php's search_path, so migrate:fresh wipes it.
| What is created is SyncSchema's.
*/

return new class extends Migration
{
    public function up(): void
    {
        SyncSchema::create();
    }

    public function down(): void
    {
        SyncSchema::drop();
    }
};
