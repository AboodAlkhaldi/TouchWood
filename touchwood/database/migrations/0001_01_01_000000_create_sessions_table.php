<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Sessions, cache and queues run on PostgreSQL for now (owner's decision, 2026-09-18); Redis comes
| back only when traffic needs it. Laravel's default users and password-reset tables are gone:
| Access creates its own customer and staff tables (handoff §7.1).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
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
        Schema::dropIfExists('sessions');
    }
};
