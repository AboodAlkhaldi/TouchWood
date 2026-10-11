<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| loyalty.md §5: a points account per customer per store, the record of each order's points, the
| ledger and the lots its points live in, what each order took from which lot, the covers a reversed
| lot settles, and every lot's history. Accounts have ULIDs; the ledger and its working rows,
| high-volume and never read by id, have bigint identity ids (handoff §5.3).
|
| Every rule is also the code's, which refuses first (handoff §5.3); every CHECK on a nullable column
| is written so that NULL fails it where a value is needed (lessons 35, 162). Rows of one account
| reach their store through composite keys, so nothing can point at another account's or store's.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty.accounts', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('customer_id');
            $table->ulid('store_id');
            $table->bigInteger('balance')->default(0);
            $table->timestampsTz();

            $table->primary('id', 'accounts_pkey');
            $table->foreign('customer_id', 'accounts_customer')->references('id')->on('access.customers')->restrictOnDelete();
            $table->foreign('store_id', 'accounts_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->unique(['customer_id', 'store_id'], 'accounts_customer_store');
            $table->unique(['id', 'store_id'], 'accounts_id_store');
        });

        DB::statement('ALTER TABLE loyalty.accounts ADD CONSTRAINT accounts_balance CHECK (balance >= 0)');

        Schema::create('loyalty.orders', function (Blueprint $table) {
            // Sales's id: no foreign key, since Loyalty never depends on Sales (handoff §4.4).
            $table->ulid('order_id');
            $table->string('order_number', 40);
            $table->ulid('account_id');
            $table->ulid('store_id');
            $table->char('currency_code', 3);
            $table->bigInteger('goods_total_minor')->nullable();
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('points_redeemed')->default(0);
            $table->bigInteger('points_earned')->default(0);
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->bigInteger('returned_goods_minor')->default(0);
            $table->bigInteger('returned_discount_minor')->default(0);
            // The max_redemption_percent it redeemed under (§1.8): an edit keeps the order's own rules.
            $table->smallInteger('redemption_cap_percent')->nullable();
            $table->bigInteger('points_given_back')->default(0);
            $table->bigInteger('points_back_through_covers')->default(0);
            $table->bigInteger('points_reversed')->default(0);
            $table->bigInteger('points_counted_gone')->default(0);
            $table->bigInteger('points_covered')->default(0);
            $table->bigInteger('points_dropped')->default(0);
            $table->bigInteger('expired_back_unused')->default(0);

            $table->primary('order_id', 'orders_pkey');
            $table->foreign(['account_id', 'store_id'], 'orders_account')->references(['id', 'store_id'])->on('loyalty.accounts')->restrictOnDelete();
            $table->foreign('currency_code', 'orders_currency')->references('code')->on('platform.currencies')->restrictOnDelete();
            $table->unique(['order_id', 'account_id'], 'orders_order_account');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty.orders
                ADD CONSTRAINT orders_number CHECK (char_length(order_number) BETWEEN 1 AND 40),
                ADD CONSTRAINT orders_not_negative CHECK (
                    COALESCE(goods_total_minor >= 0, true)
                    AND discount_minor >= 0 AND points_redeemed >= 0 AND points_earned >= 0
                    AND returned_goods_minor >= 0 AND returned_discount_minor >= 0
                    AND points_given_back >= 0 AND points_back_through_covers >= 0 AND points_reversed >= 0
                    AND points_counted_gone >= 0 AND points_covered >= 0 AND points_dropped >= 0
                    AND expired_back_unused >= 0
                ),
                ADD CONSTRAINT orders_delivered_total CHECK (delivered_at IS NULL OR goods_total_minor IS NOT NULL),
                ADD CONSTRAINT orders_before_delivery CHECK (
                    delivered_at IS NOT NULL OR (points_earned = 0 AND goods_total_minor IS NULL AND returned_goods_minor = 0)
                ),
                ADD CONSTRAINT orders_delivered_or_cancelled CHECK (delivered_at IS NULL OR cancelled_at IS NULL),
                ADD CONSTRAINT orders_redeemed_discount CHECK ((points_redeemed = 0) = (discount_minor = 0)),
                ADD CONSTRAINT orders_redemption_cap CHECK (
                    (points_redeemed = 0) = (redemption_cap_percent IS NULL)
                    AND COALESCE(redemption_cap_percent BETWEEN 0 AND 100, true)
                ),
                ADD CONSTRAINT orders_returned_goods CHECK (returned_goods_minor <= COALESCE(goods_total_minor, 0)),
                ADD CONSTRAINT orders_returned_discount CHECK (returned_discount_minor <= discount_minor),
                ADD CONSTRAINT orders_given_back CHECK (points_given_back <= points_redeemed AND points_back_through_covers <= points_given_back),
                ADD CONSTRAINT orders_earned_settled CHECK (points_reversed + points_counted_gone + points_covered + points_dropped <= points_earned)
            SQL);

        Schema::create('loyalty.order_returns', function (Blueprint $table) {
            $table->ulid('order_id');
            $table->ulid('account_id');
            // A return's id, or a staff edit's: each settles once (§1.8).
            $table->ulid('return_id');
            $table->bigInteger('returned_goods_minor');
            $table->bigInteger('returned_discount_minor');
            $table->timestampTz('settled_at')->useCurrent();

            $table->primary(['order_id', 'return_id'], 'order_returns_pkey');
            $table->foreign(['order_id', 'account_id'], 'order_returns_order')->references(['order_id', 'account_id'])->on('loyalty.orders')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE loyalty.order_returns ADD CONSTRAINT order_returns_not_negative CHECK (returned_goods_minor >= 0 AND returned_discount_minor >= 0)');

        Schema::create('loyalty.entries', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->ulid('account_id');
            $table->ulid('store_id');
            $table->string('kind', 24);
            $table->bigInteger('points');
            $table->ulid('order_id')->nullable();
            $table->ulid('return_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->ulid('staff_id')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->foreign(['account_id', 'store_id'], 'entries_account')->references(['id', 'store_id'])->on('loyalty.accounts')->restrictOnDelete();
            // Unchecked while order_id is NULL (MATCH SIMPLE): a change by hand belongs to no order.
            $table->foreign(['order_id', 'account_id'], 'entries_order')->references(['order_id', 'account_id'])->on('loyalty.orders')->restrictOnDelete();
            $table->foreign('staff_id', 'entries_staff')->references('id')->on('access.staff_users')->restrictOnDelete();
            $table->unique(['id', 'account_id'], 'entries_id_account');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty.entries
                ADD CONSTRAINT entries_kind CHECK (kind IN ('EARNED', 'REDEEMED', 'REDEMPTION_RETURNED', 'EARNING_REVERSED', 'ADDED', 'DEDUCTED', 'EXPIRED')),
                ADD CONSTRAINT entries_sign CHECK (
                    (kind IN ('EARNED', 'REDEMPTION_RETURNED', 'ADDED') AND points > 0)
                    OR (kind IN ('REDEEMED', 'EARNING_REVERSED', 'DEDUCTED', 'EXPIRED') AND points < 0)
                ),
                ADD CONSTRAINT entries_order_for_kind CHECK (
                    (kind IN ('EARNED', 'REDEEMED', 'REDEMPTION_RETURNED', 'EARNING_REVERSED') AND order_id IS NOT NULL)
                    OR (kind IN ('ADDED', 'DEDUCTED') AND order_id IS NULL)
                    OR kind = 'EXPIRED'
                ),
                ADD CONSTRAINT entries_return CHECK (return_id IS NULL OR kind IN ('REDEMPTION_RETURNED', 'EARNING_REVERSED', 'EXPIRED')),
                ADD CONSTRAINT entries_by_hand CHECK (
                    (kind IN ('ADDED', 'DEDUCTED') AND staff_id IS NOT NULL AND COALESCE(char_length(reason) BETWEEN 1 AND 500, false))
                    OR (kind NOT IN ('ADDED', 'DEDUCTED') AND reason IS NULL AND staff_id IS NULL)
                )
            SQL);

        // One earning and one redemption an order; one settlement of each kind per return, edit or
        // cancellation — a cancellation's NULL return id counts once (precedent: platform.settings).
        DB::statement("CREATE UNIQUE INDEX entries_one_earning ON loyalty.entries (order_id) WHERE kind = 'EARNED'");
        DB::statement("CREATE UNIQUE INDEX entries_one_redemption ON loyalty.entries (order_id) WHERE kind = 'REDEEMED'");
        DB::statement("CREATE UNIQUE INDEX entries_one_settlement ON loyalty.entries (kind, order_id, return_id) NULLS NOT DISTINCT WHERE kind IN ('REDEMPTION_RETURNED', 'EARNING_REVERSED')");
        DB::statement('CREATE INDEX entries_history_idx ON loyalty.entries (account_id, occurred_at DESC, id DESC)');
        DB::statement('CREATE INDEX entries_redemptions_idx ON loyalty.entries (store_id, kind, occurred_at DESC, id DESC)');

        Schema::create('loyalty.lots', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->ulid('account_id');
            // The EARNED or ADDED entry that formed it.
            $table->bigInteger('entry_id');
            // The order it was earned on; none for points added by hand.
            $table->ulid('order_id')->nullable();
            $table->bigInteger('points');
            $table->bigInteger('points_left');
            $table->timestampTz('expires_at');

            $table->foreign(['entry_id', 'account_id'], 'lots_entry')->references(['id', 'account_id'])->on('loyalty.entries')->restrictOnDelete();
            $table->foreign(['order_id', 'account_id'], 'lots_order')->references(['order_id', 'account_id'])->on('loyalty.orders')->restrictOnDelete();
            $table->unique('entry_id', 'lots_entry_unique');
            $table->unique(['id', 'account_id'], 'lots_id_account');
        });

        DB::statement('ALTER TABLE loyalty.lots ADD CONSTRAINT lots_points CHECK (points > 0 AND points_left >= 0 AND points_left <= points)');
        DB::statement('CREATE UNIQUE INDEX lots_one_per_order ON loyalty.lots (order_id) WHERE order_id IS NOT NULL');
        DB::statement('CREATE INDEX lots_spending_idx ON loyalty.lots (account_id, expires_at, id) WHERE points_left > 0');
        DB::statement('CREATE INDEX lots_sweep_idx ON loyalty.lots (expires_at) WHERE points_left > 0');

        Schema::create('loyalty.takes', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->ulid('account_id');
            $table->bigInteger('lot_id');
            // The REDEEMED or EARNING_REVERSED entry that took them.
            $table->bigInteger('entry_id');
            $table->string('kind', 16);
            $table->bigInteger('points');
            $table->bigInteger('back')->default(0);

            $table->foreign(['lot_id', 'account_id'], 'takes_lot')->references(['id', 'account_id'])->on('loyalty.lots')->restrictOnDelete();
            $table->foreign(['entry_id', 'account_id'], 'takes_entry')->references(['id', 'account_id'])->on('loyalty.entries')->restrictOnDelete();
            $table->unique(['id', 'account_id'], 'takes_id_account');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty.takes
                ADD CONSTRAINT takes_kind CHECK (kind IN ('REDEMPTION', 'COVER')),
                ADD CONSTRAINT takes_points CHECK (points > 0 AND back >= 0 AND back <= points)
            SQL);
        DB::statement('CREATE INDEX takes_lot_idx ON loyalty.takes (lot_id, id)');
        DB::statement('CREATE INDEX takes_entry_idx ON loyalty.takes (entry_id)');

        Schema::create('loyalty.covers', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->ulid('account_id');
            // The earned lot whose reversal it settles.
            $table->bigInteger('lot_id');
            $table->ulid('order_id');
            // The settlement that made it: a cover may move no point, so it is keyed to the
            // settlement, not to an entry.
            $table->ulid('return_id')->nullable();
            $table->string('source', 16);
            // The COVER take from the covering lot.
            $table->bigInteger('source_take_id')->nullable();
            $table->bigInteger('points');
            $table->bigInteger('undone')->default(0);

            $table->foreign(['lot_id', 'account_id'], 'covers_lot')->references(['id', 'account_id'])->on('loyalty.lots')->restrictOnDelete();
            $table->foreign(['order_id', 'account_id'], 'covers_order')->references(['order_id', 'account_id'])->on('loyalty.orders')->restrictOnDelete();
            $table->foreign(['source_take_id', 'account_id'], 'covers_source_take')->references(['id', 'account_id'])->on('loyalty.takes')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty.covers
                ADD CONSTRAINT covers_source CHECK (source IN ('EXPIRED_POINTS', 'LOT', 'DROPPED')),
                ADD CONSTRAINT covers_take_for_lot CHECK ((source = 'LOT') = (source_take_id IS NOT NULL)),
                ADD CONSTRAINT covers_points CHECK (points > 0 AND undone >= 0 AND undone <= points)
            SQL);
        DB::statement('CREATE INDEX covers_lot_idx ON loyalty.covers (lot_id, id)');

        Schema::create('loyalty.lot_movements', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->ulid('account_id');
            $table->bigInteger('lot_id');
            $table->bigInteger('entry_id');
            // + onto the lot, − off it.
            $table->bigInteger('points');

            $table->foreign(['lot_id', 'account_id'], 'lot_movements_lot')->references(['id', 'account_id'])->on('loyalty.lots')->restrictOnDelete();
            $table->foreign(['entry_id', 'account_id'], 'lot_movements_entry')->references(['id', 'account_id'])->on('loyalty.entries')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE loyalty.lot_movements ADD CONSTRAINT lot_movements_points CHECK (points <> 0)');
        DB::statement('CREATE INDEX lot_movements_lot_idx ON loyalty.lot_movements (lot_id)');
        DB::statement('CREATE INDEX lot_movements_entry_idx ON loyalty.lot_movements (entry_id)');

        // The ledger and every lot's history are written once and never changed (§1.2), as the audit
        // log. OR REPLACE: migrate:fresh drops tables but not functions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION loyalty.ledger_is_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'loyalty.% is append-only: % is not allowed', TG_TABLE_NAME, TG_OP;
            END;
            $$;

            CREATE TRIGGER entries_append_only
                BEFORE UPDATE OR DELETE ON loyalty.entries
                FOR EACH ROW EXECUTE FUNCTION loyalty.ledger_is_append_only();

            -- TRUNCATE skips row triggers, so it gets its own.
            CREATE TRIGGER entries_no_truncate
                BEFORE TRUNCATE ON loyalty.entries
                FOR EACH STATEMENT EXECUTE FUNCTION loyalty.ledger_is_append_only();

            CREATE TRIGGER lot_movements_append_only
                BEFORE UPDATE OR DELETE ON loyalty.lot_movements
                FOR EACH ROW EXECUTE FUNCTION loyalty.ledger_is_append_only();

            CREATE TRIGGER lot_movements_no_truncate
                BEFORE TRUNCATE ON loyalty.lot_movements
                FOR EACH STATEMENT EXECUTE FUNCTION loyalty.ledger_is_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty.lot_movements');
        Schema::dropIfExists('loyalty.covers');
        Schema::dropIfExists('loyalty.takes');
        Schema::dropIfExists('loyalty.lots');
        Schema::dropIfExists('loyalty.entries');
        Schema::dropIfExists('loyalty.order_returns');
        Schema::dropIfExists('loyalty.orders');
        Schema::dropIfExists('loyalty.accounts');
        DB::unprepared('DROP FUNCTION IF EXISTS loyalty.ledger_is_append_only()');
    }
};
