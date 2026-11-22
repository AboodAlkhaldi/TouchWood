<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Payments\Infrastructure\Persistence\PaymentsSchema;

return new class extends Migration
{
    public function up(): void
    {
        PaymentsSchema::create();
    }

    public function down(): void
    {
        PaymentsSchema::drop();
    }
};
