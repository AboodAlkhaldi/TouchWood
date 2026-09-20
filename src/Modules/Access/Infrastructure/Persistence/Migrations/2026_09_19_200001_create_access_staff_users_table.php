<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Access spec §5.3 with amendments 13, 15, 16 and 29: the full profile is required at invitation;
| the phone is unique among staff and the email unique regardless of case, both among accounts not
| CANCELLED (a cancelled invitation frees them). Every CHECK and unique index is first enforced in
| code (StaffUser, StaffProfile, EmailAddress, PhoneNumber, CountryCode and the handlers); the
| database is the last line of defence.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access.staff_users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('email', 254);
            // A hash, set when the invitation is accepted.
            $table->string('password', 255)->nullable();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('job_title', 100);
            $table->date('date_of_birth');
            $table->char('country', 2);
            $table->string('address', 500)->nullable();
            // Null only after a Super Admin's phone is reset (amendment 14).
            $table->string('phone', 16)->nullable();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->char('avatar_media_id', 26)->nullable();
            // The communication language (amendment 16).
            $table->char('locale', 2);
            $table->string('status', 16);
            $table->boolean('is_super_admin')->default(false);
            // Who invited them, kept through resends; null when the console did (amendment 29).
            $table->char('invited_by', 26)->nullable();
            // Raised when the password changes: every session signed in before then ends.
            $table->unsignedInteger('session_version')->default(0);
            $table->timestampsTz();

            $table->foreign('avatar_media_id')->references('id')->on('platform.media')->restrictOnDelete();
        });

        // A key on the table itself, once its primary key exists.
        Schema::table('access.staff_users', function (Blueprint $table) {
            $table->foreign('invited_by')->references('id')->on('access.staff_users')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE access.staff_users
                ADD CONSTRAINT staff_users_status CHECK (status IN ('INVITED', 'ACTIVE', 'DISABLED', 'CANCELLED')),
                ADD CONSTRAINT staff_users_locale CHECK (locale IN ('ar', 'en')),
                ADD CONSTRAINT staff_users_country_format CHECK (country ~ '^[A-Z]{2}$'),
                ADD CONSTRAINT staff_users_phone_format CHECK (phone ~ '^\+[1-9][0-9]{6,14}$'),
                ADD CONSTRAINT staff_users_phone_verified_has_phone CHECK (phone_verified_at IS NULL OR phone IS NOT NULL),
                ADD CONSTRAINT staff_users_active_has_password CHECK (status <> 'ACTIVE' OR password IS NOT NULL),
                ADD CONSTRAINT staff_users_invited_has_no_password CHECK (status <> 'INVITED' OR password IS NULL),
                ADD CONSTRAINT staff_users_disabled_has_password CHECK (status <> 'DISABLED' OR password IS NOT NULL),
                ADD CONSTRAINT staff_users_cancelled_has_no_password CHECK (status <> 'CANCELLED' OR password IS NULL),
                ADD CONSTRAINT staff_users_not_invited_by_self CHECK (invited_by IS NULL OR invited_by <> id),
                ADD CONSTRAINT staff_users_date_of_birth_range CHECK (date_of_birth > DATE '1900-01-01')
            SQL);
        DB::statement("CREATE UNIQUE INDEX staff_users_email_unique ON access.staff_users (lower(email)) WHERE status <> 'CANCELLED'");
        DB::statement("CREATE UNIQUE INDEX staff_users_phone_unique ON access.staff_users (phone) WHERE phone IS NOT NULL AND status <> 'CANCELLED'");
    }

    public function down(): void
    {
        Schema::dropIfExists('access.staff_users');
    }
};
