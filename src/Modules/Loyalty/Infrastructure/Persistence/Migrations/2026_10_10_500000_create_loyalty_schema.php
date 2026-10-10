<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Loyalty\Infrastructure\Persistence\LoyaltySchema;

/*
| loyalty.md §5. The schema is also on config/database.php's search_path, so migrate:fresh wipes it.
| The tables come with step 2.
*/

return new class extends Migration
{
    public function up(): void
    {
        LoyaltySchema::create();
    }

    public function down(): void
    {
        LoyaltySchema::drop();
    }
};
