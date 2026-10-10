<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Pricing\Infrastructure\Persistence\PricingSchema;

/*
| pricing.md §5. The schema is also on config/database.php's search_path, so migrate:fresh wipes it.
| What is created is PricingSchema's.
*/

return new class extends Migration
{
    public function up(): void
    {
        PricingSchema::create();
    }

    public function down(): void
    {
        PricingSchema::drop();
    }
};
