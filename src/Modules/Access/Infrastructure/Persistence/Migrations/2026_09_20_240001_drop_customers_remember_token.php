<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Laravel's own "remember me" column, which this shop never used: a remembered customer is a flag
| in their session, not a token (spec §1.8, amendment 39). Nothing wrote or read it, and it was the
| one column anonymizing did not clear — a loose end rather than a leak (owner, 2026-09-20).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access.customers', function (Blueprint $table) {
            $table->dropColumn('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('access.customers', function (Blueprint $table) {
            $table->string('remember_token', 100)->nullable();
        });
    }
};
