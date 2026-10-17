<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| promotions.md §5, the segments' part: a store's customer segments — picked by hand or filled by one
| rule — and their members; and the foreign key a coupon's assignment keeps to the segment it came
| from (same store only; the segment's delete leaves the assignment, without it).
|
| Every rule is also the code's (handoff §5.3); every CHECK on a nullable column is written so that
| NULL fails it where a value is needed (lessons 35, 162); every kind column has its own CHECK.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions.segments', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->string('type', 10);
            $table->string('audience', 12);
            $table->string('rule', 20)->nullable();
            $table->smallInteger('rule_months')->nullable();
            $table->smallInteger('rule_orders')->nullable();
            $table->timestampTz('refreshed_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'segments_pkey');
            $table->foreign('store_id', 'segments_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->unique(['id', 'store_id'], 'segments_id_store');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE promotions.segments
                ADD CONSTRAINT segments_type CHECK (type IN ('STATIC', 'DYNAMIC')),
                ADD CONSTRAINT segments_audience CHECK (audience IN ('INDIVIDUAL', 'COMPANY')),
                ADD CONSTRAINT segments_rule CHECK (rule IS NULL OR rule IN ('NO_ORDERS_EVER', 'NO_ORDER_FOR', 'AT_LEAST_ORDERS')),
                ADD CONSTRAINT segments_type_rule CHECK (
                    (type = 'STATIC' AND rule IS NULL AND rule_months IS NULL AND rule_orders IS NULL)
                    OR (type = 'DYNAMIC' AND rule IS NOT NULL)
                ),
                ADD CONSTRAINT segments_no_orders_ever CHECK (rule IS DISTINCT FROM 'NO_ORDERS_EVER' OR (rule_months IS NULL AND rule_orders IS NULL)),
                ADD CONSTRAINT segments_no_order_for CHECK (rule IS DISTINCT FROM 'NO_ORDER_FOR' OR (rule_months IS NOT NULL AND rule_months BETWEEN 1 AND 120 AND rule_orders IS NULL)),
                ADD CONSTRAINT segments_at_least_orders CHECK (rule IS DISTINCT FROM 'AT_LEAST_ORDERS' OR (rule_months IS NOT NULL AND rule_months BETWEEN 1 AND 120 AND rule_orders IS NOT NULL AND rule_orders BETWEEN 1 AND 1000))
            SQL);

        Schema::create('promotions.segment_members', function (Blueprint $table) {
            $table->ulid('segment_id');
            $table->ulid('customer_id');
            $table->timestampTz('added_at');

            $table->primary(['segment_id', 'customer_id'], 'segment_members_pkey');
            $table->foreign('segment_id', 'segment_members_segment')->references('id')->on('promotions.segments')->cascadeOnDelete();
            $table->foreign('customer_id', 'segment_members_customer')->references('id')->on('access.customers')->restrictOnDelete();
            // A customer's segments (§5, indexes).
            $table->index('customer_id', 'segment_members_by_customer');
        });

        // A segment of the coupon's own store only; its delete keeps the assignment, without the
        // segment (only segment_id is set to NULL — PostgreSQL 15's column list).
        DB::statement(<<<'SQL'
            ALTER TABLE promotions.coupon_assignments
                ADD CONSTRAINT coupon_assignments_segment FOREIGN KEY (segment_id, store_id)
                    REFERENCES promotions.segments (id, store_id) ON DELETE SET NULL (segment_id)
            SQL);

        // A segment's delete finds its assignments by this, rather than reading them all.
        DB::statement('CREATE INDEX coupon_assignments_by_segment ON promotions.coupon_assignments (segment_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS promotions.coupon_assignments_by_segment');
        DB::statement('ALTER TABLE promotions.coupon_assignments DROP CONSTRAINT IF EXISTS coupon_assignments_segment');
        Schema::dropIfExists('promotions.segment_members');
        Schema::dropIfExists('promotions.segments');
    }
};
