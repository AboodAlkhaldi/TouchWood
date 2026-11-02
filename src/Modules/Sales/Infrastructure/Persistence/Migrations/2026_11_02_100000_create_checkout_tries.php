<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Infrastructure\Eloquent\OrderNumberSequences;

/**
 * Paying online (sales.md §9.5 #4, #5, #11, #14): no order until it is paid, so what is being paid is a try.
 * A cart's version, stamped on its quotes, tells a quote made before the cart last changed (payments.md §1.1
 * rule 3). Test orders are marked, and test money with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The test numbers too, made with the store's own, so two first test orders at once never race to create them.
        OrderNumberSequences::createForStores(DB::connection());

        Schema::table('sales.carts', function (Blueprint $table) {
            $table->bigInteger('version')->default(0);
        });

        Schema::table('sales.quotes', function (Blueprint $table) {
            $table->bigInteger('cart_version')->default(0);
        });

        Schema::table('sales.orders', function (Blueprint $table) {
            $table->boolean('is_test')->default(false);
        });

        Schema::table('sales.payments', function (Blueprint $table) {
            $table->boolean('is_test')->default(false);
            $table->boolean('mismatch')->default(false);
        });

        DB::statement('CREATE INDEX orders_test ON sales.orders (store_id) WHERE is_test');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sales.payment_marks_are_kept() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF (NEW.is_test, NEW.mismatch) IS DISTINCT FROM (OLD.is_test, OLD.mismatch) THEN
                    RAISE EXCEPTION 'sales.payments marks never change';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payments_marks_kept BEFORE UPDATE ON sales.payments
                FOR EACH ROW EXECUTE FUNCTION sales.payment_marks_are_kept();
            SQL);

        Schema::create('sales.checkout_tries', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->ulid('customer_id');
            // No key: a cart is emptied or deleted while the try stays.
            $table->ulid('cart_id');
            $table->bigInteger('cart_version');
            // No key: a released try's quote goes at its expiry.
            $table->ulid('quote_id');
            // The order's future id, under which everything is reserved: no order exists until it is paid.
            $table->ulid('order_id');
            // Payments' own, no key across modules.
            $table->ulid('attempt_id');
            $table->string('session_id', 100);
            $table->string('state', 8);
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->timestampTz('window_ends_at');
            $table->bigInteger('refund_due_minor')->nullable();
            // The copies the order is made from, gone once the try ends.
            $table->jsonb('address')->nullable();
            $table->jsonb('contact')->nullable();
            $table->timestampTz('short_of_stock_at')->nullable();
            $table->boolean('needs_attention')->default(false);
            // The customer was shown how it ended: their new order, or the closed store's one page (payments.md §1.10).
            $table->timestampTz('seen_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('ended_at')->nullable();

            $table->primary('id', 'checkout_tries_pkey');
            $table->foreign('store_id', 'checkout_tries_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('customer_id', 'checkout_tries_customer')->references('id')->on('access.customers')->restrictOnDelete();
            $table->unique('order_id', 'checkout_tries_order');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sales.checkout_tries
                ADD CONSTRAINT checkout_tries_state CHECK (state IN ('LIVE', 'PAID', 'RELEASED')),
                ADD CONSTRAINT checkout_tries_amount CHECK (amount_minor > 0),
                ADD CONSTRAINT checkout_tries_refund CHECK (refund_due_minor IS NULL OR refund_due_minor > 0),
                ADD CONSTRAINT checkout_tries_ended CHECK ((state = 'LIVE') = (ended_at IS NULL)),
                ADD CONSTRAINT checkout_tries_copies CHECK (state <> 'LIVE' OR (address IS NOT NULL AND contact IS NOT NULL))
            SQL);

        // One live try per cart, and one try per quote that is not released (§9.5 #11).
        DB::statement("CREATE UNIQUE INDEX checkout_tries_live_cart ON sales.checkout_tries (cart_id) WHERE state = 'LIVE'");
        DB::statement("CREATE UNIQUE INDEX checkout_tries_kept_quote ON sales.checkout_tries (quote_id) WHERE state <> 'RELEASED'");
        // The hourly look at the tries past their window.
        DB::statement("CREATE INDEX checkout_tries_unseen_paid ON sales.checkout_tries (customer_id, store_id, ended_at) WHERE state = 'PAID' AND seen_at IS NULL");
        DB::statement("CREATE INDEX checkout_tries_live_window ON sales.checkout_tries (window_ends_at) WHERE state = 'LIVE'");
    }

    public function down(): void
    {
        Schema::dropIfExists('sales.checkout_tries');
        DB::unprepared(<<<'SQL'
            DROP TRIGGER payments_marks_kept ON sales.payments;
            DROP FUNCTION sales.payment_marks_are_kept();
            SQL);
        DB::statement('DROP INDEX sales.orders_test');

        Schema::table('sales.payments', function (Blueprint $table) {
            $table->dropColumn(['is_test', 'mismatch']);
        });

        Schema::table('sales.orders', function (Blueprint $table) {
            $table->dropColumn('is_test');
        });

        Schema::table('sales.quotes', function (Blueprint $table) {
            $table->dropColumn('cart_version');
        });

        Schema::table('sales.carts', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
