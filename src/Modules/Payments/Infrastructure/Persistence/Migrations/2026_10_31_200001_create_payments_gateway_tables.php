<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A store's gateway and its keys (payments.md §1.2, §5). At most one gateway a store, MyFatoorah the only
| one; test or live, on or off. Its keys are kept encrypted with the app's key - never shown again but by
| their last 6 characters - one current key a store and mode; an earlier one stays, retired, until the
| attempts made with it close (rule 14). `key_rejected` is MyFatoorah's 401 on the current key: online
| payment stops by itself until a new key is set (§1.2). `warned_days` is the last expiry warning sent
| for the key - 30, 7 or 1 - so each is sent once (§1.2; gap 8.7).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments.gateways', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->string('gateway', 20);
            $table->boolean('active');
            $table->string('mode', 4);
            $table->boolean('key_rejected')->default(false);
            $table->jsonb('apple_pay_domains')->default('[]');
            $table->ulid('created_by');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('store_id', 'gateways_pkey');
            $table->foreign('store_id', 'gateways_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payments.gateways
                ADD CONSTRAINT gateways_gateway CHECK (gateway IN ('MYFATOORAH')),
                ADD CONSTRAINT gateways_mode CHECK (mode IN ('TEST', 'LIVE'))
            SQL);

        Schema::create('payments.gateway_keys', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->string('mode', 4);
            $table->text('key_encrypted');
            $table->char('key_last6', 6);
            $table->date('key_expires_on');
            $table->text('secret_encrypted');
            $table->boolean('current');
            $table->smallInteger('warned_days')->nullable();
            $table->ulid('set_by');
            $table->timestampTz('set_at');
            $table->timestampTz('retired_at')->nullable();

            $table->primary('id', 'gateway_keys_pkey');
            $table->foreign('store_id', 'gateway_keys_gateway')->references('store_id')->on('payments.gateways')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payments.gateway_keys
                ADD CONSTRAINT gateway_keys_mode CHECK (mode IN ('TEST', 'LIVE')),
                ADD CONSTRAINT gateway_keys_current CHECK (current = (retired_at IS NULL)),
                ADD CONSTRAINT gateway_keys_warned CHECK (warned_days IS NULL OR warned_days IN (30, 7, 1))
            SQL);
        DB::statement('CREATE UNIQUE INDEX gateway_keys_one_current ON payments.gateway_keys (store_id, mode) WHERE current');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments.gateway_keys');
        Schema::dropIfExists('payments.gateways');
    }
};
