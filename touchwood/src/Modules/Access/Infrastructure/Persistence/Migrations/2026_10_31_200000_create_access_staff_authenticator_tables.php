<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Amendment 91 (owner, 2026-10-31): staff sign in with an authenticator app. The secret is kept
| encrypted with the app's key, never in plain text; recovery codes only as keyed hashes. Every CHECK
| is first enforced in code (StaffAuthenticators, the handlers).
*/

return new class extends Migration
{
    public function up(): void
    {
        // One row per staff member who has started or finished setting up an app.
        Schema::create('access.staff_authenticators', function (Blueprint $table) {
            $table->char('staff_user_id', 26)->primary();
            // The app in use, encrypted; null until a setup is confirmed by its first code.
            $table->text('secret')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            // The last 30-second step a code was accepted for: a code is never taken twice.
            $table->bigInteger('last_used_step')->nullable();
            // False when the person chose SMS while both methods are ticked; the app is kept.
            $table->boolean('in_use')->default(true);
            // Wrong app or recovery codes in the sign-in under way.
            $table->unsignedSmallInteger('attempts')->default(0);
            // A setup waiting for its first code (15 minutes after the password), encrypted.
            $table->text('pending_secret')->nullable();
            $table->timestampTz('pending_at')->nullable();
            $table->unsignedSmallInteger('pending_attempts')->default(0);
            // Sign-ins whose wrong app codes ran out, counted over 24 hours from the first; the third
            // locks the app step until the password is reset (owner, 2026-10-31).
            $table->unsignedSmallInteger('used_up_rounds')->default(0);
            $table->timestampTz('rounds_since')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('staff_user_id')->references('id')->on('access.staff_users')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE access.staff_authenticators
                ADD CONSTRAINT staff_authenticators_confirmed CHECK ((secret IS NULL) = (confirmed_at IS NULL)),
                ADD CONSTRAINT staff_authenticators_pending CHECK ((pending_secret IS NULL) = (pending_at IS NULL)),
                ADD CONSTRAINT staff_authenticators_something CHECK (secret IS NOT NULL OR pending_secret IS NOT NULL)
            SQL);

        // The ten one-time codes that sign in in place of an app code, as keyed hashes.
        Schema::create('access.staff_recovery_codes', function (Blueprint $table) {
            $table->char('staff_user_id', 26);
            $table->char('code_hash', 64);
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at');

            $table->primary(['staff_user_id', 'code_hash']);
            $table->foreign('staff_user_id')->references('id')->on('access.staff_users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access.staff_recovery_codes');
        Schema::dropIfExists('access.staff_authenticators');
    }
};
