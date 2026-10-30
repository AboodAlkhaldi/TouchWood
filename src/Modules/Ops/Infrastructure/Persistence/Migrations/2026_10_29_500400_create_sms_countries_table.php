<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The SMS countries (owner, 2026-10-29; access.md amendment 90): the countries whose phones SMS
| reaches, each with its calling code - typed by a Super Admin, digits only - and the provider that
| sends to it. One provider may serve many countries. A phone from a country not here is refused
| wherever an account phone is entered, and no code is sent to it.
|
| Calling codes are prefix-free (ITU), so a number's country is the listed code its digits start
| with; one code belongs to one country. The country's name is PHP's, so only its code is kept.
|
| The start list is Saudi Arabia and Egypt, both on `log` until a provider is chosen. Each CHECK is
| the last line: SmsCountry refuses the value first.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops.sms_countries', function (Blueprint $table) {
            $table->char('country_code', 2);
            $table->string('calling_code', 3);
            $table->string('provider', 32);
            $table->timestampsTz();

            $table->primary('country_code', 'sms_countries_pkey');
            $table->unique('calling_code', 'sms_countries_calling_code_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ops.sms_countries
                ADD CONSTRAINT sms_countries_country_code CHECK (country_code ~ '^[A-Z]{2}$'),
                ADD CONSTRAINT sms_countries_calling_code CHECK (calling_code ~ '^[1-9][0-9]{0,2}$'),
                ADD CONSTRAINT sms_countries_provider CHECK (provider ~ '^[a-z][a-z0-9_]{0,31}$')
            SQL);

        DB::table('ops.sms_countries')->insert([
            ['country_code' => 'SA', 'calling_code' => '966', 'provider' => 'log', 'created_at' => now(), 'updated_at' => now()],
            ['country_code' => 'EG', 'calling_code' => '20', 'provider' => 'log', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ops.sms_countries');
    }
};
