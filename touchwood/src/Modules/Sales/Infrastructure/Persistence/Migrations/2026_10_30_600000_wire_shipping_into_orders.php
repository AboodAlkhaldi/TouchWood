<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| sales.md §9.5 #2, #3, #11 (stage 7 step 6): Shipping's carriers on the order. The carrier ordered with
| and its names, snapshotted at placement (staff may change it); the carrier shipped with — one of the
| store's, or "Other carrier" typed (no id) — in both languages, beside the tracking number, and the full
| tracking link stored when it ships; "Shipping fee to be set" (a place no carrier serves: the fee 0
| until staff set it, never shipped before); the free-above amount that applied, with whose it was —
| none when it is never free (0 meant that before, and is a real amount now).
*/

return new class extends Migration
{
    /** The history's kinds as created (2026_10_20_600000), and the shipment corrected by staff (gap 6.5). */
    private const array HISTORY_KINDS = [
        'PLACED', 'APPROVED', 'REJECTED', 'CANCELLED', 'EDITED', 'SHORT_FLAGGED', 'SHORT_CLEARED',
        'TICKED_REDUCED', 'SHIP_UNPAID_ALLOWED', 'SHIPPED', 'DELIVERED', 'CAME_BACK',
        'PAYMENT_RECORDED', 'REFUND_RECORDED', 'MONEY_VOIDED', 'TRANSFER_DOCUMENT_UPLOADED',
        'RETURN_REQUESTED', 'RETURN_WITHDRAWN', 'RETURN_ACCEPTED', 'RETURN_REFUSED', 'RETURN_RECEIVED',
        'RETURN_CLOSED', 'RETURN_COMPLETED',
    ];

    private const string CORRECTED = 'SHIPMENT_CORRECTED';

    public function up(): void
    {
        DB::statement('ALTER TABLE sales.orders RENAME COLUMN carrier TO carrier_en');

        Schema::table('sales.orders', function (Blueprint $table) {
            $table->ulid('ordered_carrier_id')->nullable();
            $table->string('ordered_carrier_ar', 100)->nullable();
            $table->string('ordered_carrier_en', 100)->nullable();
            $table->ulid('carrier_id')->nullable();
            $table->string('carrier_ar', 100)->nullable();
            $table->string('tracking_link', 500)->nullable();
            $table->boolean('shipping_fee_pending')->default(false);
            $table->string('shipping_free_kind', 8)->nullable();
        });

        DB::statement('ALTER TABLE sales.orders ALTER COLUMN shipping_rule_free_above_minor DROP NOT NULL');
        DB::statement('UPDATE sales.orders SET shipping_rule_free_above_minor = NULL WHERE shipping_rule_free_above_minor = 0');
        // An amount kept before was the store's own setting (the flat fee's free-above).
        DB::statement("UPDATE sales.orders SET shipping_free_kind = 'STORE' WHERE shipping_rule_free_above_minor IS NOT NULL");
        DB::statement('UPDATE sales.orders SET carrier_ar = carrier_en WHERE carrier_en IS NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE sales.orders
                DROP CONSTRAINT orders_carrier,
                DROP CONSTRAINT orders_tracking_carrier,
                ADD CONSTRAINT orders_carrier CHECK ((shipment_status = 'NOT_SHIPPED') = (carrier_en IS NULL)),
                ADD CONSTRAINT orders_carrier_names CHECK ((carrier_ar IS NULL) = (carrier_en IS NULL)),
                ADD CONSTRAINT orders_carrier_id CHECK (carrier_id IS NULL OR carrier_en IS NOT NULL),
                ADD CONSTRAINT orders_tracking_carrier CHECK (carrier_en IS NOT NULL OR (tracking_number IS NULL AND tracking_link IS NULL)),
                ADD CONSTRAINT orders_tracking_link CHECK (tracking_link IS NULL OR (tracking_link ILIKE 'https://%' AND char_length(tracking_link) BETWEEN 9 AND 500)),
                ADD CONSTRAINT orders_ordered_carrier CHECK (num_nonnulls(ordered_carrier_id, ordered_carrier_ar, ordered_carrier_en) IN (0, 3)),
                ADD CONSTRAINT orders_fee_pending CHECK (
                    NOT shipping_fee_pending OR (shipping_fee_minor = 0 AND shipment_status = 'NOT_SHIPPED' AND ordered_carrier_id IS NULL)
                ),
                ADD CONSTRAINT orders_free_kind CHECK (
                    COALESCE(shipping_free_kind IN ('CARRIER', 'STORE'), true) AND (shipping_free_kind IS NULL) = (shipping_rule_free_above_minor IS NULL)
                )
            SQL);

        self::historyKinds([...self::HISTORY_KINDS, self::CORRECTED]);
    }

    public function down(): void
    {
        DB::table('sales.order_history')->where('kind', self::CORRECTED)->exists()
            ? throw new RuntimeException('Shipments were corrected since: their history cannot go back.')
            : self::historyKinds(self::HISTORY_KINDS);

        DB::statement(<<<'SQL'
            ALTER TABLE sales.orders
                DROP CONSTRAINT orders_carrier,
                DROP CONSTRAINT orders_carrier_names,
                DROP CONSTRAINT orders_carrier_id,
                DROP CONSTRAINT orders_tracking_carrier,
                DROP CONSTRAINT orders_tracking_link,
                DROP CONSTRAINT orders_ordered_carrier,
                DROP CONSTRAINT orders_fee_pending,
                DROP CONSTRAINT orders_free_kind
            SQL);

        DB::statement('UPDATE sales.orders SET shipping_rule_free_above_minor = 0 WHERE shipping_rule_free_above_minor IS NULL');
        DB::statement('ALTER TABLE sales.orders ALTER COLUMN shipping_rule_free_above_minor SET NOT NULL');

        Schema::table('sales.orders', function (Blueprint $table) {
            $table->dropColumn(['ordered_carrier_id', 'ordered_carrier_ar', 'ordered_carrier_en', 'carrier_id', 'carrier_ar', 'tracking_link', 'shipping_fee_pending', 'shipping_free_kind']);
        });

        DB::statement('ALTER TABLE sales.orders RENAME COLUMN carrier_en TO carrier');

        DB::statement(<<<'SQL'
            ALTER TABLE sales.orders
                ADD CONSTRAINT orders_carrier CHECK ((shipment_status = 'NOT_SHIPPED') = (carrier IS NULL)),
                ADD CONSTRAINT orders_tracking_carrier CHECK (carrier IS NOT NULL OR tracking_number IS NULL)
            SQL);
    }

    /** @param  list<string>  $kinds */
    private static function historyKinds(array $kinds): void
    {
        DB::statement('ALTER TABLE sales.order_history DROP CONSTRAINT order_history_kind');
        DB::statement("ALTER TABLE sales.order_history ADD CONSTRAINT order_history_kind CHECK (kind IN ('".implode("', '", $kinds)."'))");
    }
};
