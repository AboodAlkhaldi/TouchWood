<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Access spec §5.3, the part roles need: who a staff member is, their status, and whether they
| are a Super Admin. The password, phone and profile columns, the unique email and the language
| rule come with staff sign-in, together with the code that writes them (every constraint ships
| with its code-level rule, owner 2026-09-18).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access.staff_users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('email', 254);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->char('locale', 2);
            $table->string('status', 16);
            $table->boolean('is_super_admin')->default(false);
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE access.staff_users
                ADD CONSTRAINT staff_users_status CHECK (status IN ('INVITED', 'ACTIVE', 'DISABLED'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('access.staff_users');
    }
};
