<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| sales.md §5, the money recorded against an order (§1.9): each payment and each refund staff record by
| hand — its amount in the order's own currency, the way, a reference, a note, the day the money moved,
| the form's request id (pressing twice records once). A record is never changed or deleted; one entered
| by mistake is voided once, with a reason — a trigger refuses anything else.
*/

return new class extends Migration
{
    public function up(): void
    {
        foreach (['payments', 'refunds'] as $table) {
            Schema::create("sales.{$table}", function (Blueprint $blueprint) use ($table) {
                $blueprint->ulid('id');
                $blueprint->ulid('order_id');
                $blueprint->ulid('store_id');
                $blueprint->char('currency_code', 3);
                $blueprint->bigInteger('amount_minor');
                $blueprint->string('way', 13);
                $blueprint->string('reference', 100)->nullable();
                $blueprint->string('note', 500)->nullable();
                $blueprint->date('happened_on');
                $blueprint->uuid('request_id');
                $blueprint->ulid('recorded_by')->nullable();
                $blueprint->timestampTz('recorded_at');
                $blueprint->ulid('voided_by')->nullable();
                $blueprint->timestampTz('voided_at')->nullable();
                $blueprint->string('void_reason', 500)->nullable();

                $blueprint->primary('id', "{$table}_pkey");
                // The order's own currency only.
                $blueprint->foreign(['order_id', 'store_id', 'currency_code'], "{$table}_order")->references(['id', 'store_id', 'currency_code'])->on('sales.orders')->restrictOnDelete();
                $blueprint->foreign('recorded_by', "{$table}_recorded_by")->references('id')->on('access.staff_users')->restrictOnDelete();
                $blueprint->foreign('voided_by', "{$table}_voided_by")->references('id')->on('access.staff_users')->restrictOnDelete();
                $blueprint->unique('request_id', "{$table}_request");
            });

            DB::statement(<<<SQL
                ALTER TABLE sales.{$table}
                    ADD CONSTRAINT {$table}_amount CHECK (amount_minor > 0),
                    ADD CONSTRAINT {$table}_way CHECK (way IN ('BANK_TRANSFER', 'ONLINE', 'OTHER')),
                    ADD CONSTRAINT {$table}_other_noted CHECK (way <> 'OTHER' OR note IS NOT NULL),
                    ADD CONSTRAINT {$table}_void CHECK (num_nonnulls(voided_by, voided_at, void_reason) IN (0, 3))
                SQL);

            // Stage 7's gateway references, once each.
            DB::statement("CREATE UNIQUE INDEX {$table}_online_reference ON sales.{$table} (way, reference) WHERE way = 'ONLINE'");
            DB::statement("CREATE INDEX {$table}_by_order ON sales.{$table} (order_id)");
        }

        // Never changed or deleted: only the void columns, set once (§1.9). OR REPLACE: migrate:fresh
        // drops tables but not functions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sales.money_record_is_kept() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'UPDATE' THEN
                    RAISE EXCEPTION 'sales.% records are never deleted: % is not allowed', TG_TABLE_NAME, TG_OP;
                END IF;

                IF OLD.voided_at IS NOT NULL OR NEW.voided_at IS NULL
                    OR (NEW.id, NEW.order_id, NEW.store_id, NEW.currency_code, NEW.amount_minor, NEW.way, NEW.reference, NEW.note,
                        NEW.happened_on, NEW.request_id, NEW.recorded_by, NEW.recorded_at)
                    IS DISTINCT FROM
                        (OLD.id, OLD.order_id, OLD.store_id, OLD.currency_code, OLD.amount_minor, OLD.way, OLD.reference, OLD.note,
                        OLD.happened_on, OLD.request_id, OLD.recorded_by, OLD.recorded_at) THEN
                    RAISE EXCEPTION 'sales.% records are only voided, once', TG_TABLE_NAME;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payments_kept BEFORE UPDATE OR DELETE ON sales.payments
                FOR EACH ROW EXECUTE FUNCTION sales.money_record_is_kept();
            CREATE TRIGGER payments_no_truncate BEFORE TRUNCATE ON sales.payments
                FOR EACH STATEMENT EXECUTE FUNCTION sales.is_append_only();
            CREATE TRIGGER refunds_kept BEFORE UPDATE OR DELETE ON sales.refunds
                FOR EACH ROW EXECUTE FUNCTION sales.money_record_is_kept();
            CREATE TRIGGER refunds_no_truncate BEFORE TRUNCATE ON sales.refunds
                FOR EACH STATEMENT EXECUTE FUNCTION sales.is_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sales.refunds');
        Schema::dropIfExists('sales.payments');
        DB::unprepared('DROP FUNCTION IF EXISTS sales.money_record_is_kept()');
    }
};
