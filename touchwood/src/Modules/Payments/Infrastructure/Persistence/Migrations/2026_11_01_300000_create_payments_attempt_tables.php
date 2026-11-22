<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The attempts and their transactions (payments.md §4, §5). Rule 3's index allows one open attempt per owner; a
| gateway's invoice and payment ids are unique per gateway and mode, since test and live may share a number.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments.attempts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('store_id', 26);
            $table->string('gateway', 20);
            $table->string('owner_kind', 10);
            $table->string('owner_id', 64);
            $table->string('order_id', 64);
            $table->string('buyer_id', 64);
            $table->string('session_id', 100);
            $table->char('key_id', 26);
            $table->string('mode', 4);
            $table->char('currency_code', 3);
            $table->bigInteger('amount_minor');
            $table->string('state', 12);
            $table->uuid('idempotency_key')->unique('attempts_idempotency');
            $table->string('locale', 5);
            $table->string('return_url', 2000);
            $table->timestampTz('window_ends_at');
            $table->timestampTz('wait_ends_at');
            $table->timestampTz('hold_ends_at');
            $table->timestampTz('charge_claimed_at')->nullable();
            $table->string('invoice_id', 64)->nullable();
            $table->string('gateway_payment_id', 64)->nullable();
            $table->string('frame_url', 2000)->nullable();
            $table->timestampTz('next_check_at')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('checking_until')->nullable();
            $table->timestampTz('succeeded_at')->nullable();
            $table->bigInteger('paid_minor')->nullable();
            $table->boolean('mismatch')->default(false);
            $table->boolean('paid_twice')->default(false);
            $table->boolean('needs_attention')->default(false);
            $table->boolean('nothing_charged')->default(false);
            $table->boolean('currency_refused')->default(false);
            $table->string('transaction_id', 20)->nullable();
            $table->string('refused_reason', 500)->nullable();
            $table->string('customer_name', 200)->nullable();
            $table->string('customer_email', 255)->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->foreign('store_id', 'attempts_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('key_id', 'attempts_key')->references('id')->on('payments.gateway_keys')->restrictOnDelete();
            $table->unique(['gateway', 'mode', 'invoice_id'], 'attempts_invoice');
            $table->index(['owner_id', 'created_at'], 'attempts_owner');
            $table->index('buyer_id', 'attempts_buyer');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payments.attempts
                ADD CONSTRAINT attempts_gateway CHECK (gateway IN ('MYFATOORAH')),
                ADD CONSTRAINT attempts_owner_kind CHECK (owner_kind IN ('TRY', 'REQUEST')),
                ADD CONSTRAINT attempts_mode CHECK (mode IN ('TEST', 'LIVE')),
                ADD CONSTRAINT attempts_amount CHECK (amount_minor > 0),
                ADD CONSTRAINT attempts_state CHECK (state IN ('CREATING', 'CHARGING', 'OPEN', 'UNKNOWN', 'SUCCEEDED', 'FAILED', 'REFUSED', 'EXPIRED', 'ABANDONED')),
                ADD CONSTRAINT attempts_claimed CHECK (state IN ('CREATING', 'ABANDONED') OR charge_claimed_at IS NOT NULL)
        SQL);
        DB::statement("CREATE UNIQUE INDEX attempts_one_open ON payments.attempts (owner_id) WHERE state IN ('CREATING', 'CHARGING', 'OPEN', 'UNKNOWN')");
        DB::statement('CREATE INDEX attempts_next_check ON payments.attempts (next_check_at) WHERE next_check_at IS NOT NULL');

        Schema::create('payments.transactions', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->char('attempt_id', 26);
            $table->string('gateway', 20);
            $table->string('mode', 4);
            $table->string('gateway_payment_id', 64);
            $table->string('status', 32);
            $table->boolean('succeeded');
            $table->string('method', 32);
            $table->string('card_brand', 32)->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->string('amount_text', 40)->nullable();
            $table->string('currency_code', 8)->nullable();
            $table->string('base_currency_code', 8)->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->boolean('mismatch')->default(false);
            $table->boolean('paid_twice')->default(false);
            $table->boolean('published')->default(false);
            $table->timestampTz('seen_at');

            $table->foreign('attempt_id', 'transactions_attempt')->references('id')->on('payments.attempts')->restrictOnDelete();
            $table->unique(['gateway', 'mode', 'gateway_payment_id'], 'transactions_payment');
            $table->index('attempt_id', 'transactions_of_attempt');
        });

        DB::statement("ALTER TABLE payments.transactions ADD CONSTRAINT transactions_gateway CHECK (gateway IN ('MYFATOORAH')), ADD CONSTRAINT transactions_mode CHECK (mode IN ('TEST', 'LIVE'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payments.transactions');
        Schema::dropIfExists('payments.attempts');
    }
};
