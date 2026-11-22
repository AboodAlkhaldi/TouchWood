<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The sessions we opened, every call to the gateway, and the notices it sent (payments.md §5). A call's
| attempt has its key when the attempts arrive (step 10). Calls and notices keep nothing of the card or the
| customer's contact details beyond what §1.1 rule 13 allows.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments.sessions', function (Blueprint $table) {
            $table->string('gateway', 20);
            $table->string('mode', 4);
            $table->string('id', 100);
            $table->char('store_id', 26);
            $table->string('owner_id', 64);
            $table->string('buyer_id', 64);
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->timestampTz('expires_at');
            $table->char('used_by', 26)->nullable();
            $table->timestampTz('created_at');

            $table->primary(['gateway', 'mode', 'id']);
            $table->foreign('store_id', 'sessions_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->index('owner_id', 'sessions_owner');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payments.sessions
                ADD CONSTRAINT sessions_gateway CHECK (gateway IN ('MYFATOORAH')),
                ADD CONSTRAINT sessions_mode CHECK (mode IN ('TEST', 'LIVE')),
                ADD CONSTRAINT sessions_amount CHECK (amount_minor > 0)
        SQL);

        Schema::create('payments.calls', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->char('attempt_id', 26)->nullable();
            $table->char('store_id', 26);
            $table->string('endpoint', 300);
            $table->jsonb('request')->nullable();
            $table->smallInteger('http_status')->nullable();
            $table->jsonb('response')->nullable();
            $table->string('error', 500)->nullable();
            $table->integer('took_ms');
            $table->timestampTz('at');

            $table->foreign('store_id', 'calls_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->index('attempt_id', 'calls_attempt');
            $table->index('at', 'calls_at');
        });

        Schema::create('payments.notices', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->string('gateway', 20);
            $table->char('store_id', 26);
            $table->string('mode', 4);
            $table->string('event_reference', 200);
            $table->timestampTz('received_at');
            $table->jsonb('payload');
            $table->timestampTz('handled_at')->nullable();

            $table->unique(['gateway', 'mode', 'event_reference'], 'notices_reference');
            $table->foreign('store_id', 'notices_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->index('received_at', 'notices_received');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payments.notices
                ADD CONSTRAINT notices_gateway CHECK (gateway IN ('MYFATOORAH')),
                ADD CONSTRAINT notices_mode CHECK (mode IN ('TEST', 'LIVE'))
        SQL);
        DB::statement('CREATE INDEX notices_unhandled ON payments.notices (received_at) WHERE handled_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments.notices');
        Schema::dropIfExists('payments.calls');
        Schema::dropIfExists('payments.sessions');
    }
};
