<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Sales\Infrastructure\Persistence\SalesSchema;

/*
| sales.md §5. The schema is also on config/database.php's search_path, so migrate:fresh wipes it.
*/

return new class extends Migration
{
    public function up(): void
    {
        SalesSchema::create();
    }

    public function down(): void
    {
        SalesSchema::drop();
    }
};
