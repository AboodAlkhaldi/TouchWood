<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| promotions.md §5, the coupons' part: a store's coupons, what each covers, who it was assigned to,
| each use, and each placed order's fact. Coupons have ULIDs; uses, high-volume and never read by id,
| a bigint identity (handoff §5.3). Segments and gift levels come with their steps; the assignment's
| segment_id gets its foreign key with the segments table.
|
| Every rule is also the code's, which refuses first (handoff §5.3); every CHECK on a nullable column
| is written so that NULL fails it where a value is needed (lessons 35, 162); every kind column has its
| own CHECK of its values. A row pointing at a coupon carries the coupon's store through a composite
| key, so nothing of one store reaches another's.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions.coupons', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            // Stored upper case (§1.1); the CHECK below holds it to 3-30 of A-Z, 0-9 and hyphens.
            $table->string('code', 30);
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->string('eligibility', 10);
            $table->string('discount_kind', 10);
            $table->smallInteger('percent')->nullable();
            $table->bigInteger('cap_minor')->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency_code', 3);
            $table->bigInteger('minimum_minor')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->integer('max_uses_per_customer')->nullable();
            $table->integer('max_uses_total')->nullable();
            $table->integer('uses_total')->default(0);
            $table->boolean('allow_with_points');
            $table->boolean('on_reduced_lines');
            $table->boolean('for_retail');
            $table->boolean('for_wholesale');
            $table->boolean('for_public');
            $table->boolean('for_company');
            $table->boolean('active');
            // NOT NULL, as every column of §5 not marked NULL: timestampsTz() would make both nullable.
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'coupons_pkey');
            $table->foreign('store_id', 'coupons_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('currency_code', 'coupons_currency')->references('code')->on('platform.currencies')->restrictOnDelete();
            $table->unique(['store_id', 'code'], 'coupons_store_code');
            $table->unique(['id', 'store_id'], 'coupons_id_store');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE promotions.coupons
                ADD CONSTRAINT coupons_eligibility CHECK (eligibility IN ('PUBLIC', 'ASSIGNED')),
                ADD CONSTRAINT coupons_discount_kind CHECK (discount_kind IN ('PERCENT', 'FIXED')),
                ADD CONSTRAINT coupons_code CHECK (code ~ '^[A-Z0-9-]{3,30}$'),
                ADD CONSTRAINT coupons_discount CHECK (
                    (discount_kind = 'PERCENT' AND percent IS NOT NULL AND percent BETWEEN 1 AND 100 AND amount_minor IS NULL)
                    OR (discount_kind = 'FIXED' AND amount_minor IS NOT NULL AND amount_minor > 0 AND percent IS NULL AND cap_minor IS NULL)
                ),
                ADD CONSTRAINT coupons_cap CHECK (cap_minor IS NULL OR cap_minor > 0),
                ADD CONSTRAINT coupons_minimum CHECK (minimum_minor IS NULL OR minimum_minor > 0),
                ADD CONSTRAINT coupons_dates CHECK (ends_at IS NULL OR ends_at > starts_at),
                ADD CONSTRAINT coupons_max_per_customer CHECK (max_uses_per_customer IS NULL OR max_uses_per_customer > 0),
                ADD CONSTRAINT coupons_max_total CHECK (max_uses_total IS NULL OR max_uses_total > 0),
                ADD CONSTRAINT coupons_uses CHECK (uses_total >= 0 AND (max_uses_total IS NULL OR uses_total <= max_uses_total)),
                ADD CONSTRAINT coupons_sale_modes CHECK (for_retail OR for_wholesale),
                ADD CONSTRAINT coupons_audiences CHECK (for_public OR for_company)
            SQL);

        Schema::create('promotions.coupon_rules', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('coupon_id');
            $table->string('kind', 10);
            // Catalog's rows are global (handoff §4.1): no store to carry.
            $table->ulid('product_id')->nullable();
            $table->ulid('category_id')->nullable();
            $table->ulid('brand_id')->nullable();

            $table->primary('id', 'coupon_rules_pkey');
            $table->foreign('coupon_id', 'coupon_rules_coupon')->references('id')->on('promotions.coupons')->cascadeOnDelete();
            $table->foreign('product_id', 'coupon_rules_product')->references('id')->on('catalog.products')->restrictOnDelete();
            $table->foreign('category_id', 'coupon_rules_category')->references('id')->on('catalog.categories')->restrictOnDelete();
            $table->foreign('brand_id', 'coupon_rules_brand')->references('id')->on('catalog.brands')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE promotions.coupon_rules
                ADD CONSTRAINT coupon_rules_kind CHECK (kind IN ('INCLUDE', 'EXCLUDE')),
                ADD CONSTRAINT coupon_rules_one_target CHECK (num_nonnulls(product_id, category_id, brand_id) = 1),
                ADD CONSTRAINT coupon_rules_unique UNIQUE NULLS NOT DISTINCT (coupon_id, kind, product_id, category_id, brand_id)
            SQL);

        Schema::create('promotions.coupon_assignments', function (Blueprint $table) {
            $table->ulid('coupon_id');
            $table->ulid('store_id');
            $table->ulid('customer_id');
            // Its foreign key to the store's segments comes with the segments table (step 4).
            $table->ulid('segment_id')->nullable();
            $table->timestampTz('assigned_at');

            $table->primary(['coupon_id', 'customer_id'], 'coupon_assignments_pkey');
            $table->foreign('store_id', 'coupon_assignments_store')->references('id')->on('platform.stores')->restrictOnDelete();
            // An unused coupon's delete takes its assignments with it (§1.1).
            $table->foreign(['coupon_id', 'store_id'], 'coupon_assignments_coupon')->references(['id', 'store_id'])->on('promotions.coupons')->cascadeOnDelete();
            $table->foreign('customer_id', 'coupon_assignments_customer')->references('id')->on('access.customers')->restrictOnDelete();
            // "My coupons" (§5, indexes).
            $table->index('customer_id', 'coupon_assignments_by_customer');
        });

        Schema::create('promotions.coupon_uses', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->ulid('coupon_id');
            $table->ulid('store_id');
            $table->ulid('customer_id');
            // Sales's id: no foreign key, since Promotions never depends on Sales (handoff §4.4).
            $table->ulid('order_id');
            $table->string('order_number', 40);
            $table->bigInteger('discount_minor');
            $table->ulid('last_edit_id')->nullable();
            $table->timestampTz('used_at');

            $table->foreign('store_id', 'coupon_uses_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign(['coupon_id', 'store_id'], 'coupon_uses_coupon')->references(['id', 'store_id'])->on('promotions.coupons')->restrictOnDelete();
            $table->foreign('customer_id', 'coupon_uses_customer')->references('id')->on('access.customers')->restrictOnDelete();
            // One coupon per order (§1.2).
            $table->unique('order_id', 'coupon_uses_order');
            // Uses per customer (§5, indexes).
            $table->index(['coupon_id', 'customer_id'], 'coupon_uses_by_customer');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE promotions.coupon_uses
                ADD CONSTRAINT coupon_uses_discount CHECK (discount_minor >= 0),
                ADD CONSTRAINT coupon_uses_number CHECK (char_length(order_number) BETWEEN 1 AND 40)
            SQL);

        Schema::create('promotions.order_facts', function (Blueprint $table) {
            $table->ulid('order_id');
            $table->ulid('store_id');
            $table->ulid('customer_id');
            $table->timestampTz('placed_at');
            // The ceiling the order was placed under (§1.6): an edit keeps the order's own rules.
            $table->smallInteger('ceiling_percent');

            $table->primary('order_id', 'order_facts_pkey');
            $table->foreign('store_id', 'order_facts_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('customer_id', 'order_facts_customer')->references('id')->on('access.customers')->restrictOnDelete();
            // The segment rules (§5, indexes).
            $table->index(['store_id', 'customer_id', 'placed_at'], 'order_facts_by_customer');
        });

        DB::statement('ALTER TABLE promotions.order_facts ADD CONSTRAINT order_facts_ceiling CHECK (ceiling_percent BETWEEN 0 AND 100)');

        // A use never comes back (§1.1, owner 2026-10-08) and its discount is only ever lowered, by a
        // staff edit (§1.3); a placed order's fact never changes (§5). The database holds both.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION promotions.coupon_use_is_kept() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE'
                    AND NEW.discount_minor <= OLD.discount_minor
                    AND NEW.last_edit_id IS NOT NULL
                    AND (NEW.id, NEW.coupon_id, NEW.store_id, NEW.customer_id, NEW.order_id, NEW.order_number, NEW.used_at)
                        IS NOT DISTINCT FROM (OLD.id, OLD.coupon_id, OLD.store_id, OLD.customer_id, OLD.order_id, OLD.order_number, OLD.used_at)
                THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'promotions.coupon_uses: a use is kept and its discount only lowered by an edit: % is not allowed', TG_OP;
            END;
            $$;

            CREATE TRIGGER coupon_uses_kept
                BEFORE UPDATE OR DELETE ON promotions.coupon_uses
                FOR EACH ROW EXECUTE FUNCTION promotions.coupon_use_is_kept();

            -- TRUNCATE skips row triggers, so it gets its own.
            CREATE TRIGGER coupon_uses_no_truncate
                BEFORE TRUNCATE ON promotions.coupon_uses
                FOR EACH STATEMENT EXECUTE FUNCTION promotions.coupon_use_is_kept();

            -- The count of uses is what "used up" reads: lowered, it would give a use back.
            CREATE OR REPLACE FUNCTION promotions.coupon_uses_never_fall() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.uses_total < OLD.uses_total THEN
                    RAISE EXCEPTION 'promotions.coupons: a coupon''s uses never fall (% to %)', OLD.uses_total, NEW.uses_total;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER coupons_uses_never_fall
                BEFORE UPDATE OF uses_total ON promotions.coupons
                FOR EACH ROW EXECUTE FUNCTION promotions.coupon_uses_never_fall();

            CREATE OR REPLACE FUNCTION promotions.order_fact_is_kept() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'promotions.order_facts never change: % is not allowed', TG_OP;
            END;
            $$;

            CREATE TRIGGER order_facts_kept
                BEFORE UPDATE OR DELETE ON promotions.order_facts
                FOR EACH ROW EXECUTE FUNCTION promotions.order_fact_is_kept();

            CREATE TRIGGER order_facts_no_truncate
                BEFORE TRUNCATE ON promotions.order_facts
                FOR EACH STATEMENT EXECUTE FUNCTION promotions.order_fact_is_kept();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions.order_facts');
        Schema::dropIfExists('promotions.coupon_uses');
        Schema::dropIfExists('promotions.coupon_assignments');
        Schema::dropIfExists('promotions.coupon_rules');
        Schema::dropIfExists('promotions.coupons');
        DB::unprepared('DROP FUNCTION IF EXISTS promotions.order_fact_is_kept()');
        DB::unprepared('DROP FUNCTION IF EXISTS promotions.coupon_uses_never_fall()');
        DB::unprepared('DROP FUNCTION IF EXISTS promotions.coupon_use_is_kept()');
    }
};
