<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A staff request for the rest of an order (payments.md §1.5, §4, §5): one open per order. And the indexes the order's
| own reads and the Payments list need (§4.2).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments.requests', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('store_id', 26);
            $table->string('order_id', 64);
            $table->string('order_number', 32);
            $table->string('customer_id', 64);
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('mode', 4);
            $table->string('state', 12);
            $table->timestampTz('expires_at');
            $table->string('requested_by', 64);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->foreign('store_id', 'requests_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->index('order_id', 'requests_of_order');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payments.requests
                ADD CONSTRAINT requests_mode CHECK (mode IN ('TEST', 'LIVE')),
                ADD CONSTRAINT requests_amount CHECK (amount_minor > 0),
                ADD CONSTRAINT requests_state CHECK (state IN ('OPEN', 'PAID', 'EXPIRED', 'WITHDRAWN', 'NOT_ACCEPTED'))
        SQL);
        DB::statement("CREATE UNIQUE INDEX requests_one_open ON payments.requests (order_id) WHERE state = 'OPEN'");

        DB::statement('CREATE INDEX attempts_of_order ON payments.attempts (order_id, id)');
        DB::statement('CREATE INDEX attempts_of_store ON payments.attempts (store_id, id DESC)');
        DB::statement('CREATE INDEX attempts_needing_attention ON payments.attempts (store_id, id DESC) WHERE needs_attention');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments.attempts_needing_attention');
        DB::statement('DROP INDEX IF EXISTS payments.attempts_of_store');
        DB::statement('DROP INDEX IF EXISTS payments.attempts_of_order');
        Schema::dropIfExists('payments.requests');
    }
};
