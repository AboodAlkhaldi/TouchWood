<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Catalog\Infrastructure\Persistence\CatalogSchema;

/*
| catalog.md §5. The schema is also on config/database.php's search_path, so migrate:fresh wipes it.
| What is created, and why pg_trgm comes with it, is CatalogSchema's.
*/

return new class extends Migration
{
    public function up(): void
    {
        CatalogSchema::create();
    }

    public function down(): void
    {
        CatalogSchema::drop();
    }
};
