<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| promotions.md §5's indexes for the staff screens' reads (§3), each the keyset a page walks: a store's
| coupons newest first; a coupon's uses newest first; a coupon's assignments newest first.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX coupons_by_store_newest ON promotions.coupons (store_id, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX coupon_uses_by_coupon_newest ON promotions.coupon_uses (coupon_id, used_at DESC, id DESC)');
        DB::statement('CREATE INDEX coupon_assignments_by_coupon_newest ON promotions.coupon_assignments (coupon_id, assigned_at DESC, customer_id DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS promotions.coupon_assignments_by_coupon_newest');
        DB::statement('DROP INDEX IF EXISTS promotions.coupon_uses_by_coupon_newest');
        DB::statement('DROP INDEX IF EXISTS promotions.coupons_by_store_newest');
    }
};
