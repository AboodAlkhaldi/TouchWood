<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Content\Infrastructure\Persistence\ContentSchema;

/*
| content.md §5. The schema is also on config/database.php's search_path (§9.4 A2), so migrate:fresh wipes it.
*/

return new class extends Migration
{
    public function up(): void
    {
        ContentSchema::create();
    }

    public function down(): void
    {
        ContentSchema::drop();
    }
};
