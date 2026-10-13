<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| inventory.md §1.3, §2.3, step 4: whether a size could be ordered when Catalog was last told, beside
| `ending_soon`, so only a flip is pushed. Null: never pushed.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory.stock', function (Blueprint $table) {
            $table->boolean('orderable')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory.stock', function (Blueprint $table) {
            $table->dropColumn('orderable');
        });
    }
};
