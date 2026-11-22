<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Shipping\Infrastructure\Persistence\ShippingSchema;

return new class extends Migration
{
    public function up(): void
    {
        ShippingSchema::create();
    }

    public function down(): void
    {
        ShippingSchema::drop();
    }
};
