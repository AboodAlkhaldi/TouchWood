<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 7 step 12 (sales.md §9.5 #8, #9, #16): an overdue transfer is told once per order, and asking for or
 * withdrawing a payment request is in the order's history.
 */
return new class extends Migration
{
    private const array HISTORY_KINDS = [
        'PLACED', 'APPROVED', 'REJECTED', 'CANCELLED', 'EDITED', 'SHORT_FLAGGED', 'SHORT_CLEARED',
        'TICKED_REDUCED', 'SHIP_UNPAID_ALLOWED', 'SHIPPED', 'DELIVERED', 'CAME_BACK',
        'PAYMENT_RECORDED', 'REFUND_RECORDED', 'MONEY_VOIDED', 'TRANSFER_DOCUMENT_UPLOADED',
        'RETURN_REQUESTED', 'RETURN_WITHDRAWN', 'RETURN_ACCEPTED', 'RETURN_REFUSED', 'RETURN_RECEIVED',
        'RETURN_CLOSED', 'RETURN_COMPLETED', 'SHIPMENT_CORRECTED',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE sales.orders ADD COLUMN overdue_told_at timestamptz NULL');
        DB::statement("CREATE INDEX orders_transfer_unchecked ON sales.orders (store_id) WHERE way_to_pay = 'BANK_TRANSFER' AND overdue_told_at IS NULL AND NOT is_test AND paid_minor - refunded_minor < due_minor");
        self::historyKinds([...self::HISTORY_KINDS, 'PAYMENT_REQUESTED', 'PAYMENT_REQUEST_WITHDRAWN']);
    }

    public function down(): void
    {
        // History is kept: with such a row the wider list stays.
        if (! DB::table('sales.order_history')->whereIn('kind', ['PAYMENT_REQUESTED', 'PAYMENT_REQUEST_WITHDRAWN'])->exists()) {
            self::historyKinds(self::HISTORY_KINDS);
        }

        Schema::table('sales.orders', fn ($table) => $table->dropColumn('overdue_told_at'));
    }

    /** @param  list<string>  $kinds */
    private static function historyKinds(array $kinds): void
    {
        DB::statement('ALTER TABLE sales.order_history DROP CONSTRAINT order_history_kind');
        DB::statement("ALTER TABLE sales.order_history ADD CONSTRAINT order_history_kind CHECK (kind IN ('".implode("', '", $kinds)."'))");
    }
};
