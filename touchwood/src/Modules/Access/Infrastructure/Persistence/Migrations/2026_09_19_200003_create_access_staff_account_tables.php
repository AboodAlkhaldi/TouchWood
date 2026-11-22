<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Access spec §5.3 and amendment 17: invitations, phone codes, email changes and notification
| preferences. Tokens and codes are stored only as hashes (SHA-256 hex, or an HMAC for codes), so
| a copy of the database lets nobody accept an invitation or pass a code.
*/

return new class extends Migration
{
    public function up(): void
    {
        // While the invitee accepts, the password they chose waits here, hashed, until their phone's
        // code is right: a staff member has a password only once they have accepted.
        Schema::create('access.staff_invitations', function (Blueprint $table) {
            $table->char('staff_user_id', 26)->primary();
            $table->char('token_hash', 64)->unique();
            $table->string('pending_password', 255)->nullable();
            $table->timestampTz('expires_at');
            $table->char('invited_by', 26)->nullable();
            $table->timestampTz('created_at');

            $table->foreign('staff_user_id')->references('id')->on('access.staff_users')->restrictOnDelete();
            $table->foreign('invited_by')->references('id')->on('access.staff_users')->restrictOnDelete();
        });

        // One live code per staff member: a new request replaces the previous one.
        Schema::create('access.staff_phone_codes', function (Blueprint $table) {
            $table->char('staff_user_id', 26)->primary();
            $table->string('purpose', 16);
            $table->string('phone', 16);
            $table->char('code_hash', 64);
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('sent_at');

            $table->foreign('staff_user_id')->references('id')->on('access.staff_users')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE access.staff_phone_codes
                ADD CONSTRAINT staff_phone_codes_purpose CHECK (purpose IN ('ACCEPT', 'CHANGE')),
                ADD CONSTRAINT staff_phone_codes_phone_format CHECK (phone ~ '^\+[1-9][0-9]{6,14}$'),
                ADD CONSTRAINT staff_phone_codes_attempts CHECK (attempts >= 0)
            SQL);

        Schema::create('access.staff_email_changes', function (Blueprint $table) {
            $table->char('staff_user_id', 26)->primary();
            $table->string('new_email', 254);
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->char('requested_by', 26)->nullable();
            $table->timestampTz('created_at');

            $table->foreign('staff_user_id')->references('id')->on('access.staff_users')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('access.staff_users')->restrictOnDelete();
        });

        Schema::create('access.staff_notification_preferences', function (Blueprint $table) {
            $table->char('staff_user_id', 26);
            $table->string('topic', 32);
            $table->boolean('email');
            $table->boolean('panel');

            $table->primary(['staff_user_id', 'topic']);
            $table->foreign('staff_user_id')->references('id')->on('access.staff_users')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE access.staff_notification_preferences ADD CONSTRAINT staff_notification_preferences_topic CHECK (topic IN ('NEW_ORDERS', 'COMPANY_APPLICATIONS', 'LOW_STOCK', 'CAMPAIGN_EXPIRY'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('access.staff_notification_preferences');
        Schema::dropIfExists('access.staff_email_changes');
        Schema::dropIfExists('access.staff_phone_codes');
        Schema::dropIfExists('access.staff_invitations');
    }
};
