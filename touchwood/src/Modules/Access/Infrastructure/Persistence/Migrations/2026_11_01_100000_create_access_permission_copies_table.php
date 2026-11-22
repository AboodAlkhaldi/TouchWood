<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Which one-time copies of a permission have run (amendment 92): each pair once, so a box an admin later
| unticks stays unticked however many times `migrate` runs.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access.permission_copies', function (Blueprint $table) {
            $table->string('from_permission', 128);
            $table->string('to_permission', 128);
            $table->timestampTz('copied_at');

            $table->primary(['from_permission', 'to_permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access.permission_copies');
    }
};
