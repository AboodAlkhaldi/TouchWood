<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| promotions.md §1.3, §5: a use's discount is lowered once per staff edit, and each lowering names a
| new edit. The trigger of 2026_10_17_200000 already refused a raise, a delete and a moved use; it now
| also refuses an update that keeps the last edit's id — the code's "once per edit", held by the
| database as well (Promotions step 2b's review). Down puts the earlier function back.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION promotions.coupon_use_is_kept() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE'
                    AND NEW.discount_minor <= OLD.discount_minor
                    AND NEW.last_edit_id IS NOT NULL
                    AND NEW.last_edit_id IS DISTINCT FROM OLD.last_edit_id
                    AND (NEW.id, NEW.coupon_id, NEW.store_id, NEW.customer_id, NEW.order_id, NEW.order_number, NEW.used_at)
                        IS NOT DISTINCT FROM (OLD.id, OLD.coupon_id, OLD.store_id, OLD.customer_id, OLD.order_id, OLD.order_number, OLD.used_at)
                THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'promotions.coupon_uses: a use is kept and its discount only lowered by an edit: % is not allowed', TG_OP;
            END;
            $$;
            SQL);
    }

    public function down(): void
    {
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
            SQL);
    }
};
