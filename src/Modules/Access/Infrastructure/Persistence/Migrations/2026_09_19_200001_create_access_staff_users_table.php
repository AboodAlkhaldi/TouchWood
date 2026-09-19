<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Access spec §5.3 with amendments 13, 15 and 16: the full profile is required at invitation, the
| phone is unique among staff, the email unique regardless of case. Every CHECK and unique index is
| first enforced in code (StaffUser, StaffProfile, EmailAddress, PhoneNumber, CountryCode and the
| handlers); the database is the last line of defence.
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
            $table->timestampsTz();

            $table->foreign('avatar_media_id')->references('id')->on('platform.media')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE access.staff_users
                ADD CONSTRAINT staff_users_status CHECK (status IN ('INVITED', 'ACTIVE', 'DISABLED')),
                ADD CONSTRAINT staff_users_locale CHECK (locale IN ('ar', 'en')),
                ADD CONSTRAINT staff_users_country_format CHECK (country ~ '^[A-Z]{2}$'),
                ADD CONSTRAINT staff_users_phone_format CHECK (phone ~ '^\+[1-9][0-9]{6,14}$'),
                ADD CONSTRAINT staff_users_phone_verified_has_phone CHECK (phone_verified_at IS NULL OR phone IS NOT NULL),
                ADD CONSTRAINT staff_users_active_has_password CHECK (status <> 'ACTIVE' OR password IS NOT NULL),
                ADD CONSTRAINT staff_users_invited_has_no_password CHECK (status <> 'INVITED' OR password IS NULL),
                ADD CONSTRAINT staff_users_date_of_birth_range CHECK (date_of_birth > DATE '1900-01-01')
            SQL);
        DB::statement('CREATE UNIQUE INDEX staff_users_email_unique ON access.staff_users (lower(email))');
        DB::statement('CREATE UNIQUE INDEX staff_users_phone_unique ON access.staff_users (phone) WHERE phone IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('access.staff_users');
    }
};
