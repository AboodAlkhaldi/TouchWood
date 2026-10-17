<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Promotions\Infrastructure\Persistence\PromotionsSchema;

/*
| promotions.md §5. The schema is also on config/database.php's search_path, so migrate:fresh wipes it.
*/

return new class extends Migration
{
    public function up(): void
    {
        PromotionsSchema::create();
    }

    public function down(): void
    {
        PromotionsSchema::drop();
    }
};
