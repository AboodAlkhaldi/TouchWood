<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Inventory\Infrastructure\Persistence\InventorySchema;

/*
| inventory.md §5. The schema is also on config/database.php's search_path, so migrate:fresh wipes
| it. What is created is InventorySchema's.
*/

return new class extends Migration
{
    public function up(): void
    {
        InventorySchema::create();
    }

    public function down(): void
    {
        InventorySchema::drop();
    }
};
