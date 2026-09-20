<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Access spec §5.1 and §5.2: one customer account for every store, its email unique regardless of
| case and its phone unique across customers. The deletion columns are created now and used in step
| 6. Every CHECK and unique index is first enforced in code (Customer, EmailAddress, PhoneNumber and
| the handlers); the database is the last line of defence.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access.customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('email', 254);
            $table->string('password', 255);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('account_type', 16);
            $table->string('status', 16);
            $table->timestampTz('email_verified_at')->nullable();
            // Null until the first code is right; never null again (spec §1.3).
            $table->string('phone', 16)->nullable();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->char('locale', 2);
            // The store they registered in, fixed: it decides which staff see them (spec §3.3).
            $table->char('home_store_id', 26);
            // Where they last shopped: after signing in they land there, on any device.
            $table->char('last_store_id', 26);
            // That store's terms, as accepted at registration (amendment 37).
            $table->string('terms_version', 32);
            $table->timestampTz('terms_accepted_at');
            $table->timestampTz('deletion_scheduled_for')->nullable();
            $table->timestampTz('anonymized_at')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestampsTz();

            $table->foreign('home_store_id')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('last_store_id')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE access.customers
                ADD CONSTRAINT customers_account_type CHECK (account_type IN ('INDIVIDUAL', 'COMPANY')),
                ADD CONSTRAINT customers_status CHECK (status IN ('ACTIVE', 'BLOCKED')),
                ADD CONSTRAINT customers_locale CHECK (locale IN ('ar', 'en')),
                ADD CONSTRAINT customers_phone_format CHECK (phone ~ '^\+[1-9][0-9]{6,14}$'),
                ADD CONSTRAINT customers_phone_verified_together CHECK ((phone IS NULL) = (phone_verified_at IS NULL))
            SQL);
        DB::statement('CREATE UNIQUE INDEX customers_email_unique ON access.customers (lower(email))');
        DB::statement('CREATE UNIQUE INDEX customers_phone_unique ON access.customers (phone) WHERE phone IS NOT NULL');

        Schema::create('access.phone_codes', function (Blueprint $table) {
            // One live code per customer: adding a phone, or changing it (spec §5.2).
            $table->ulid('customer_id')->primary();
            $table->string('purpose', 16);
            $table->string('phone', 16);
            $table->char('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('sent_at');

            $table->foreign('customer_id')->references('id')->on('access.customers')->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE access.phone_codes
                ADD CONSTRAINT phone_codes_purpose CHECK (purpose IN ('ADD', 'CHANGE')),
                ADD CONSTRAINT phone_codes_phone_format CHECK (phone ~ '^\+[1-9][0-9]{6,14}$')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('access.phone_codes');
        Schema::dropIfExists('access.customers');
    }
};
