<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| promotions.md §5, for Ops (§2.1, §6; 2026-10-28):
| - the store's coupon uses over a range, for the daily report (`couponsUsed`) — without the index the count
|   walks every use of every store;
| - each assignment's own id on the rows it added (`assignment_id`), which `CouponAssigned` carries and
|   `assignedCustomers` pages by. The rows written before have none: no event ever named them.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX coupon_uses_by_store_time ON promotions.coupon_uses (store_id, used_at)');
        DB::statement('ALTER TABLE promotions.coupon_assignments ADD COLUMN assignment_id char(26) NULL');
        DB::statement('CREATE INDEX coupon_assignments_by_assignment ON promotions.coupon_assignments (assignment_id, customer_id) WHERE assignment_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS promotions.coupon_assignments_by_assignment');
        DB::statement('ALTER TABLE promotions.coupon_assignments DROP COLUMN IF EXISTS assignment_id');
        DB::statement('DROP INDEX IF EXISTS promotions.coupon_uses_by_store_time');
    }
};
