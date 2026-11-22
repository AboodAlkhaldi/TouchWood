<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Stage 2b, P3. The admin panel carries no store in its URLs, so the store a person is working in is
| remembered on their account rather than in the browser (owner, 2026-09-19): it then follows them
| between machines.
|
| Nullable, because a staff member has chosen nothing until they do, and ON DELETE SET NULL because
| closing a store must never block anything - the panel simply opens in their first remaining store.
| It is a preference, not permission: every read still filters by the person's own stores, so a
| stale value here can never widen what they see.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access.staff_users', function (Blueprint $table) {
            $table->char('current_store_id', 26)->nullable();

            $table->foreign('current_store_id')->references('id')->on('platform.stores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('access.staff_users', function (Blueprint $table) {
            $table->dropForeign(['current_store_id']);
            $table->dropColumn('current_store_id');
        });
    }
};
