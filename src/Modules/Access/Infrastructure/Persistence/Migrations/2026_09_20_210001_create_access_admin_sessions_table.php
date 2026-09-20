<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The admin panel keeps its sessions in its own table (owner, 2026-09-20). Laravel deletes old
| session rows with the lifetime of whatever request happens to sweep — an admin request carries
| twelve hours, a storefront one a year — and it deletes from one table, with no idea which side a
| row belongs to. Two tables, so neither side can end the other's sessions (review of step 4b).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access.admin_sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            // Every account id is a ULID (handoff §5.3); Laravel's default was a bigint.
            $table->ulid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access.admin_sessions');
    }
};
