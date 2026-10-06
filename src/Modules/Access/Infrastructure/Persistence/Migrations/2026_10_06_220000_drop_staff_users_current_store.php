<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The panel no longer has a store "worked in" (the owner, 2026-10-06; access.md amendment 64): each
| store screen chooses its own store, so the store remembered on the staff account goes, with its
| foreign key. It was a preference and recorded nothing anyone may ask about later.
|
| Undone, the column comes back empty - nobody has a store remembered - as it began (stage 2b, P3).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access.staff_users', function (Blueprint $table): void {
            $table->dropForeign(['current_store_id']);
            $table->dropColumn('current_store_id');
        });
    }

    public function down(): void
    {
        Schema::table('access.staff_users', function (Blueprint $table): void {
            $table->char('current_store_id', 26)->nullable();
            $table->foreign('current_store_id')->references('id')->on('platform.stores')->nullOnDelete();
        });
    }
};
