<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A coupon held while its customer pays online (promotions.md §9.5, stage 7 step 7): one row per checkout
| try, under the order's future id. A hold counts toward the coupon's limits while it lasts, becomes a use
| when the order is made, and goes, counting nothing, when the try does not pay. A coupon deleted under a
| hold takes the hold with it (owner, 2026-10-29, gap 7.1 (b): staff may change or delete a coupon at any
| time; the paid order keeps its own discount).
|
| `coupons_uses` no longer caps the count at the limit: a hold confirmed after staff lowered the limit is
| still recorded (gap 7.1 (b): "100 of 99"). The limit is decided where uses are taken - `useCoupon` and
| `reserveCoupon`, counting holds - and the count still never falls (`coupon_uses_never_fall`).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions.coupon_reservations', function (Blueprint $table) {
            $table->ulid('order_id');
            $table->ulid('coupon_id');
            $table->ulid('store_id');
            $table->ulid('customer_id');
            $table->bigInteger('discount_minor');
            $table->timestampTz('reserved_at');

            $table->primary('order_id', 'coupon_reservations_pkey');
            $table->foreign(['coupon_id', 'store_id'], 'coupon_reservations_coupon')->references(['id', 'store_id'])->on('promotions.coupons')->cascadeOnDelete();
            $table->foreign('customer_id', 'coupon_reservations_customer')->references('id')->on('access.customers')->restrictOnDelete();
            $table->index(['coupon_id', 'customer_id'], 'coupon_reservations_by_customer');
        });

        DB::statement('ALTER TABLE promotions.coupon_reservations ADD CONSTRAINT coupon_reservations_discount CHECK (discount_minor > 0)');
        DB::statement('ALTER TABLE promotions.coupons DROP CONSTRAINT coupons_uses');
        DB::statement('ALTER TABLE promotions.coupons ADD CONSTRAINT coupons_uses CHECK (uses_total >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE promotions.coupons DROP CONSTRAINT coupons_uses');
        DB::statement('ALTER TABLE promotions.coupons ADD CONSTRAINT coupons_uses CHECK (uses_total >= 0 AND (max_uses_total IS NULL OR uses_total <= max_uses_total))');
        Schema::dropIfExists('promotions.coupon_reservations');
    }
};
