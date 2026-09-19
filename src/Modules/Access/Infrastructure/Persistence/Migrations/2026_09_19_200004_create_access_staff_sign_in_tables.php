<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Access spec §1.8 and §5.3: signing in (step 3b). Links, codes and trusted browsers are stored only
| as hashes. Every CHECK is first enforced in code (PhoneNumber, PhoneVerification, the handlers).
*/

return new class extends Migration
{
    public function up(): void
    {
        // The second step of signing in: one live code per staff member, kept apart from the codes
        // that verify a phone, so signing in never ends a phone change.
        Schema::create('access.staff_sign_in_codes', function (Blueprint $table) {
            $table->char('staff_user_id', 26)->primary();
            // The number the code went to: their phone, or a new one after a reset (amendment 14).
            $table->string('phone', 16);
            $table->char('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('sent_at');

            $table->foreign('staff_user_id')->references('id')->on('access.staff_users')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE access.staff_sign_in_codes
                ADD CONSTRAINT staff_sign_in_codes_phone_format CHECK (phone ~ '^\+[1-9][0-9]{6,14}$')
            SQL);

        // A reset link: one live link per staff member (amendment 31: 30 minutes).
        Schema::create('access.staff_password_resets', function (Blueprint $table) {
            $table->char('staff_user_id', 26)->primary();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at');

            $table->foreign('staff_user_id')->references('id')->on('access.staff_users')->restrictOnDelete();
        });

        // Browsers where the SMS code is not asked again until the trust expires.
        Schema::create('access.staff_trusted_browsers', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('staff_user_id', 26);
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at');
            $table->timestampTz('last_used_at');

            $table->foreign('staff_user_id')->references('id')->on('access.staff_users')->restrictOnDelete();
            $table->index('staff_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access.staff_trusted_browsers');
        Schema::dropIfExists('access.staff_password_resets');
        Schema::dropIfExists('access.staff_sign_in_codes');
    }
};
