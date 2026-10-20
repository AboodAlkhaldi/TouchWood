<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Infrastructure\Eloquent\OrderNumberSequences;

/*
| sales.md §5, the order's part: the order — everything snapshotted at placement (handoff §12.3) and its
| four state machines' columns, each machine's rules a CHECK — its lines with their coupon, points and
| VAT shares, the edits that changed them, the sizes ticked "reduced in the provider", and the order's
| history, every change once. The rows of one order carry its store through composite keys. One number
| sequence a store, from 1001 (answer 35): made here for the stores there are, by `CreateOrderSequence`
| for each store opened later.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales.orders', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->string('number', 24);
            $table->ulid('quote_id');
            $table->ulid('customer_id');
            $table->string('audience', 7);
            $table->string('locale', 2);
            $table->char('currency_code', 3);
            $table->string('customer_name', 255);
            $table->string('customer_email', 254)->nullable();
            $table->string('customer_phone', 20);
            $table->jsonb('address');
            $table->string('status', 16);
            $table->string('shipment_status', 11);
            $table->string('way_to_pay', 13);
            $table->ulid('coupon_id')->nullable();
            $table->string('coupon_code', 64)->nullable();
            $table->bigInteger('coupon_discount_minor');
            $table->integer('points_used');
            $table->bigInteger('points_discount_minor');
            $table->bigInteger('gross_subtotal_minor');
            $table->bigInteger('net_subtotal_minor');
            $table->bigInteger('goods_total_minor');
            $table->bigInteger('shipping_fee_minor');
            $table->bigInteger('shipping_rule_fee_minor');
            $table->bigInteger('shipping_rule_free_above_minor');
            $table->bigInteger('taxable_base_minor');
            $table->bigInteger('vat_minor');
            $table->integer('vat_rate_basis_points');
            $table->bigInteger('order_total_minor');
            $table->bigInteger('due_minor');
            $table->bigInteger('paid_minor');
            $table->bigInteger('refunded_minor');
            $table->boolean('may_ship_unpaid');
            $table->timestampTz('short_of_stock_at')->nullable();
            $table->ulid('gift_level_id')->nullable();
            $table->timestampTz('placed_at');
            $table->ulid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->string('cancel_reason', 18)->nullable();
            $table->string('cancel_other', 500)->nullable();
            $table->ulid('cancelled_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('carrier', 100)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('return_deadline_at')->nullable();
            $table->ulid('came_back_id')->nullable();
            $table->timestampTz('came_back_at')->nullable();

            $table->primary('id', 'orders_pkey');
            $table->foreign('store_id', 'orders_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('customer_id', 'orders_customer')->references('id')->on('access.customers')->restrictOnDelete();
            $table->foreign('currency_code', 'orders_currency')->references('code')->on('platform.currencies')->restrictOnDelete();
            $table->foreign('decided_by', 'orders_decided_by')->references('id')->on('access.staff_users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'orders_cancelled_by')->references('id')->on('access.staff_users')->restrictOnDelete();
            $table->unique(['store_id', 'number'], 'orders_number');
            $table->unique('quote_id', 'orders_one_per_quote');
            $table->unique(['id', 'store_id'], 'orders_in_store');
            $table->unique(['id', 'store_id', 'currency_code'], 'orders_in_currency');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sales.orders
                ADD CONSTRAINT orders_audience CHECK (audience IN ('PUBLIC', 'COMPANY')),
                ADD CONSTRAINT orders_locale CHECK (locale IN ('ar', 'en')),
                ADD CONSTRAINT orders_status CHECK (status IN ('PENDING_APPROVAL', 'APPROVED', 'REJECTED', 'CANCELLED', 'COMPLETED', 'UNDELIVERED')),
                ADD CONSTRAINT orders_shipment_status CHECK (shipment_status IN ('NOT_SHIPPED', 'SHIPPED', 'DELIVERED', 'CAME_BACK')),
                ADD CONSTRAINT orders_way_to_pay CHECK (way_to_pay IN ('ONLINE', 'BANK_TRANSFER', 'STAFF_CONTACT')),
                ADD CONSTRAINT orders_cancel_reason CHECK (cancel_reason IN ('CHANGED_MIND', 'ORDERED_BY_MISTAKE', 'FOUND_CHEAPER', 'TOO_SLOW', 'OTHER', 'BY_STAFF')),
                ADD CONSTRAINT orders_decision CHECK (
                    (status = 'PENDING_APPROVAL' AND decided_at IS NULL)
                    OR (status IN ('APPROVED', 'REJECTED', 'COMPLETED', 'UNDELIVERED') AND decided_at IS NOT NULL)
                    OR status = 'CANCELLED'
                ),
                ADD CONSTRAINT orders_decided_together CHECK ((decided_by IS NULL) = (decided_at IS NULL)),
                ADD CONSTRAINT orders_rejection_reason CHECK (status <> 'REJECTED' OR decision_reason IS NOT NULL),
                ADD CONSTRAINT orders_cancelled_at CHECK ((status = 'CANCELLED') = (cancelled_at IS NOT NULL)),
                ADD CONSTRAINT orders_cancelled_reason CHECK ((status = 'CANCELLED') = (cancel_reason IS NOT NULL)),
                ADD CONSTRAINT orders_cancelled_unshipped CHECK (status <> 'CANCELLED' OR shipment_status = 'NOT_SHIPPED'),
                ADD CONSTRAINT orders_cancel_other CHECK (COALESCE(cancel_reason IN ('OTHER', 'BY_STAFF'), false) = (cancel_other IS NOT NULL)),
                ADD CONSTRAINT orders_cancelled_by_staff CHECK ((cancel_reason IS NOT DISTINCT FROM 'BY_STAFF') = (cancelled_by IS NOT NULL)),
                ADD CONSTRAINT orders_shipped_at CHECK ((shipment_status = 'NOT_SHIPPED') = (shipped_at IS NULL)),
                ADD CONSTRAINT orders_delivered_at CHECK ((shipment_status = 'DELIVERED') = (delivered_at IS NOT NULL)),
                ADD CONSTRAINT orders_came_back_together CHECK (num_nonnulls(came_back_at, came_back_id) IN (0, 2)),
                ADD CONSTRAINT orders_came_back_at CHECK ((shipment_status = 'CAME_BACK') = (came_back_at IS NOT NULL)),
                ADD CONSTRAINT orders_carrier CHECK ((shipment_status = 'NOT_SHIPPED') = (carrier IS NULL)),
                ADD CONSTRAINT orders_tracking_carrier CHECK (carrier IS NOT NULL OR tracking_number IS NULL),
                ADD CONSTRAINT orders_completed_delivered CHECK ((status = 'COMPLETED') = (shipment_status = 'DELIVERED')),
                ADD CONSTRAINT orders_undelivered_came_back CHECK ((status = 'UNDELIVERED') = (shipment_status = 'CAME_BACK')),
                ADD CONSTRAINT orders_shipped_approved CHECK (shipment_status = 'NOT_SHIPPED' OR status IN ('APPROVED', 'COMPLETED', 'UNDELIVERED')),
                ADD CONSTRAINT orders_return_deadline CHECK ((delivered_at IS NOT NULL) = (return_deadline_at IS NOT NULL) AND COALESCE(return_deadline_at >= delivered_at, true)),
                ADD CONSTRAINT orders_coupon_together CHECK ((coupon_id IS NULL) = (coupon_code IS NULL)),
                ADD CONSTRAINT orders_coupon_discount CHECK (coupon_id IS NOT NULL OR coupon_discount_minor = 0),
                ADD CONSTRAINT orders_points CHECK ((points_used = 0) = (points_discount_minor = 0)),
                ADD CONSTRAINT orders_amounts CHECK (
                    coupon_discount_minor >= 0 AND points_used >= 0 AND points_discount_minor >= 0 AND gross_subtotal_minor >= 0
                    AND net_subtotal_minor >= 0 AND goods_total_minor >= 0 AND shipping_fee_minor >= 0 AND shipping_rule_fee_minor >= 0
                    AND shipping_rule_free_above_minor >= 0 AND taxable_base_minor >= 0 AND vat_minor >= 0 AND vat_rate_basis_points >= 0
                    AND order_total_minor >= 0 AND due_minor >= 0 AND paid_minor >= 0 AND refunded_minor >= 0
                ),
                ADD CONSTRAINT orders_due CHECK (due_minor <= order_total_minor),
                ADD CONSTRAINT orders_refunded CHECK (refunded_minor <= paid_minor)
            SQL);

        // The lists, keyset-paged; the unpaid; the flagged short; a customer's own; the open ones (§5).
        DB::statement('CREATE INDEX orders_by_placed ON sales.orders (store_id, placed_at DESC, id)');
        DB::statement('CREATE INDEX orders_unpaid ON sales.orders (store_id) WHERE paid_minor - refunded_minor < due_minor');
        DB::statement('CREATE INDEX orders_short ON sales.orders (store_id) WHERE short_of_stock_at IS NOT NULL');
        DB::statement('CREATE INDEX orders_by_customer ON sales.orders (customer_id, placed_at DESC)');
        DB::statement("CREATE INDEX orders_open ON sales.orders (store_id) WHERE status IN ('PENDING_APPROVAL', 'APPROVED')");

        Schema::create('sales.order_edits', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('order_id');
            $table->ulid('store_id');
            $table->string('reason', 500);
            $table->ulid('by');
            $table->timestampTz('at');
            $table->jsonb('before');
            $table->jsonb('after');

            $table->primary('id', 'order_edits_pkey');
            $table->foreign(['order_id', 'store_id'], 'order_edits_order')->references(['id', 'store_id'])->on('sales.orders')->restrictOnDelete();
            $table->foreign('by', 'order_edits_by')->references('id')->on('access.staff_users')->restrictOnDelete();
            $table->unique(['id', 'order_id'], 'order_edits_of_order');
        });

        DB::statement('ALTER TABLE sales.order_edits ADD CONSTRAINT order_edits_reason CHECK (char_length(reason) BETWEEN 1 AND 500)');
        DB::statement('CREATE INDEX order_edits_by_order ON sales.order_edits (order_id)');

        Schema::create('sales.order_lines', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('order_id');
            $table->ulid('store_id');
            $table->ulid('product_id');
            $table->ulid('variant_id');
            $table->ulid('edit_id')->nullable();
            $table->boolean('is_gift');
            $table->string('name_ar', 255);
            $table->string('name_en', 255);
            $table->jsonb('variant_values');
            $table->string('code', 64);
            $table->string('sale_mode', 9);
            $table->string('price_kind', 8)->nullable();
            $table->integer('quantity');
            $table->bigInteger('unit_minor');
            $table->bigInteger('list_unit_minor');
            $table->bigInteger('total_minor');
            $table->bigInteger('coupon_share_minor');
            $table->bigInteger('points_share_minor');
            $table->bigInteger('vat_share_minor');

            $table->primary('id', 'order_lines_pkey');
            $table->foreign(['order_id', 'store_id'], 'order_lines_order')->references(['id', 'store_id'])->on('sales.orders')->restrictOnDelete();
            $table->foreign(['edit_id', 'order_id'], 'order_lines_edit')->references(['id', 'order_id'])->on('sales.order_edits')->restrictOnDelete();
            $table->foreign('product_id', 'order_lines_product')->references('id')->on('catalog.products')->restrictOnDelete();
            $table->foreign('variant_id', 'order_lines_variant')->references('id')->on('catalog.variants')->restrictOnDelete();
            $table->unique(['id', 'order_id'], 'order_lines_of_order');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sales.order_lines
                ADD CONSTRAINT order_lines_sale_mode CHECK (sale_mode IN ('RETAIL', 'WHOLESALE')),
                ADD CONSTRAINT order_lines_price_kind CHECK (price_kind IN ('BASE', 'SALE', 'CAMPAIGN', 'CATEGORY', 'QUANTITY')),
                ADD CONSTRAINT order_lines_gift_unpriced CHECK ((price_kind IS NULL) = is_gift),
                ADD CONSTRAINT order_lines_quantity CHECK (quantity >= 1),
                ADD CONSTRAINT order_lines_amounts CHECK (unit_minor >= 0 AND list_unit_minor >= 0 AND total_minor >= 0),
                ADD CONSTRAINT order_lines_shares CHECK (coupon_share_minor >= 0 AND points_share_minor >= 0 AND vat_share_minor >= 0),
                ADD CONSTRAINT order_lines_discount_within CHECK (coupon_share_minor + points_share_minor <= total_minor),
                ADD CONSTRAINT order_lines_gift_free CHECK (
                    NOT is_gift OR (sale_mode = 'RETAIL' AND unit_minor = 0 AND list_unit_minor = 0 AND total_minor = 0
                        AND coupon_share_minor = 0 AND points_share_minor = 0 AND vat_share_minor = 0)
                )
            SQL);

        DB::statement('CREATE UNIQUE INDEX order_lines_one_gift ON sales.order_lines (order_id) WHERE is_gift');
        // An order's lines; deliveredPurchase joins a product's lines to their order's delivery (§5).
        DB::statement('CREATE INDEX order_lines_by_order ON sales.order_lines (order_id)');
        DB::statement('CREATE INDEX order_lines_by_product ON sales.order_lines (product_id, order_id)');
        DB::statement('CREATE INDEX order_lines_by_variant ON sales.order_lines (variant_id)');

        Schema::create('sales.order_ticks', function (Blueprint $table) {
            $table->ulid('order_id');
            $table->ulid('store_id');
            $table->ulid('variant_id');
            $table->ulid('ticked_by');
            $table->timestampTz('ticked_at');

            $table->primary(['order_id', 'variant_id'], 'order_ticks_pkey');
            $table->foreign(['order_id', 'store_id'], 'order_ticks_order')->references(['id', 'store_id'])->on('sales.orders')->restrictOnDelete();
            $table->foreign('ticked_by', 'order_ticks_by')->references('id')->on('access.staff_users')->restrictOnDelete();
        });

        Schema::create('sales.order_history', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->ulid('order_id');
            $table->ulid('store_id');
            $table->string('kind', 32);
            $table->ulid('edit_id')->nullable();
            $table->ulid('by')->nullable();
            $table->timestampTz('at');
            $table->jsonb('detail');

            $table->foreign(['order_id', 'store_id'], 'order_history_order')->references(['id', 'store_id'])->on('sales.orders')->restrictOnDelete();
            $table->foreign(['edit_id', 'order_id'], 'order_history_edit')->references(['id', 'order_id'])->on('sales.order_edits')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE sales.order_history ADD CONSTRAINT order_history_kind CHECK (kind IN ('".implode("', '", self::HISTORY_KINDS)."'))");
        DB::statement('CREATE INDEX order_history_by_order ON sales.order_history (order_id, id)');

        // An edit and the history are written once and never changed (§5). OR REPLACE: migrate:fresh
        // drops tables but not functions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sales.is_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'sales.% is append-only: % is not allowed', TG_TABLE_NAME, TG_OP;
            END;
            $$;

            CREATE TRIGGER order_edits_append_only
                BEFORE UPDATE OR DELETE ON sales.order_edits
                FOR EACH ROW EXECUTE FUNCTION sales.is_append_only();

            -- TRUNCATE skips row triggers, so it gets its own.
            CREATE TRIGGER order_edits_no_truncate
                BEFORE TRUNCATE ON sales.order_edits
                FOR EACH STATEMENT EXECUTE FUNCTION sales.is_append_only();

            CREATE TRIGGER order_history_append_only
                BEFORE UPDATE OR DELETE ON sales.order_history
                FOR EACH ROW EXECUTE FUNCTION sales.is_append_only();

            CREATE TRIGGER order_history_no_truncate
                BEFORE TRUNCATE ON sales.order_history
                FOR EACH STATEMENT EXECUTE FUNCTION sales.is_append_only();
            SQL);

        // One number sequence a store there is; a store opened later gets its own (CreateOrderSequence).
        OrderNumberSequences::createForStores(DB::connection());
    }

    /** Every change §3's commands make, and placing (§5 `order_history`). */
    private const array HISTORY_KINDS = [
        'PLACED', 'APPROVED', 'REJECTED', 'CANCELLED', 'EDITED', 'SHORT_FLAGGED', 'SHORT_CLEARED',
        'TICKED_REDUCED', 'SHIP_UNPAID_ALLOWED', 'SHIPPED', 'DELIVERED', 'CAME_BACK',
        'PAYMENT_RECORDED', 'REFUND_RECORDED', 'MONEY_VOIDED', 'TRANSFER_DOCUMENT_UPLOADED',
        'RETURN_REQUESTED', 'RETURN_WITHDRAWN', 'RETURN_ACCEPTED', 'RETURN_REFUSED', 'RETURN_RECEIVED',
        'RETURN_CLOSED', 'RETURN_COMPLETED',
    ];

    public function down(): void
    {
        foreach (DB::table('platform.stores')->pluck('id') as $storeId) {
            DB::statement('DROP SEQUENCE IF EXISTS '.OrderNumberSequences::name((string) $storeId));
        }

        Schema::dropIfExists('sales.order_history');
        Schema::dropIfExists('sales.order_ticks');
        Schema::dropIfExists('sales.order_lines');
        Schema::dropIfExists('sales.order_edits');
        Schema::dropIfExists('sales.orders');
        DB::unprepared('DROP FUNCTION IF EXISTS sales.is_append_only()');
    }
};
