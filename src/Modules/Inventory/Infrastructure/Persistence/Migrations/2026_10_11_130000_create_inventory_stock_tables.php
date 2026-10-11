<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| inventory.md §5, step 2: each size's stock in a store, the products whose "last pieces" staff switched
| on, and the ledger of every change of in stock (§1.6) - written once, never changed, as the audit log.
| A store is never deleted from under its stock; a size or product that goes takes its stock with it,
| while the ledger keeps its history, naming the size as the audit log names a subject.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory.stock', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->ulid('variant_id');
            $table->ulid('product_id');
            $table->integer('in_stock')->default(0);
            $table->integer('held')->default(0);
            $table->integer('threshold')->nullable();
            $table->boolean('stock_dependent')->default(false);
            $table->integer('returned_extra')->default(0);
            $table->boolean('ending_soon')->default(false);
            $table->timestampTz('updated_at');

            $table->primary(['store_id', 'variant_id'], 'stock_pkey');
            $table->foreign('store_id', 'stock_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('variant_id', 'stock_variant')->references('id')->on('catalog.variants')->cascadeOnDelete();
            $table->foreign('product_id', 'stock_product')->references('id')->on('catalog.products')->cascadeOnDelete();
            $table->index('variant_id', 'stock_variant_idx');
            $table->index('product_id', 'stock_product_idx');
        });

        // Never below 0 (§1.2); a threshold, when a size has its own, from 0 to 100,000 (owner,
        // 2026-10-10) - NULL is the store's default, so the CHECK lets only NULL through (lesson 162).
        DB::statement('ALTER TABLE inventory.stock ADD CONSTRAINT stock_in_stock CHECK (in_stock >= 0)');
        DB::statement('ALTER TABLE inventory.stock ADD CONSTRAINT stock_held CHECK (held >= 0)');
        DB::statement('ALTER TABLE inventory.stock ADD CONSTRAINT stock_threshold CHECK (threshold IS NULL OR threshold BETWEEN 0 AND 100000)');
        DB::statement('ALTER TABLE inventory.stock ADD CONSTRAINT stock_returned_extra CHECK (returned_extra >= 0)');

        Schema::create('inventory.store_products', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->ulid('product_id');
            $table->boolean('ending_soon')->default(false);
            $table->timestampTz('updated_at');

            $table->primary(['store_id', 'product_id'], 'store_products_pkey');
            $table->foreign('store_id', 'store_products_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('product_id', 'store_products_product')->references('id')->on('catalog.products')->cascadeOnDelete();
            $table->index('product_id', 'store_products_product_idx');
        });

        Schema::create('inventory.stock_movements', function (Blueprint $table) {
            // bigint identity (handoff §5.3), as the audit log.
            $table->id()->generatedAs()->always();
            $table->ulid('store_id');
            $table->ulid('variant_id');
            $table->string('reason', 16);
            $table->integer('change');
            $table->integer('before');
            $table->integer('after');
            $table->string('note', 500)->nullable();
            $table->string('reference', 120);
            $table->string('actor_type', 16);
            $table->char('actor_id', 26)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique('reference', 'stock_movements_reference');
            $table->foreign('store_id', 'stock_movements_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->index(['store_id', 'variant_id', 'id'], 'stock_movements_size_idx');
        });

        DB::statement("ALTER TABLE inventory.stock_movements ADD CONSTRAINT stock_movements_reason CHECK (reason IN ('STOCKTAKE', 'RECEIVED', 'DAMAGED_LOST', 'OFFLINE_SALE', 'CORRECTION', 'SHIPPED', 'RETURNED', 'FILE', 'PROVIDER'))");
        DB::statement('ALTER TABLE inventory.stock_movements ADD CONSTRAINT stock_movements_counts CHECK (before >= 0 AND after >= 0 AND after = before + change)');
        // A correction says why (§1.5): a note that is there and not blank.
        DB::statement("ALTER TABLE inventory.stock_movements ADD CONSTRAINT stock_movements_note CHECK (reason <> 'CORRECTION' OR (note IS NOT NULL AND btrim(note) <> ''))");
        DB::statement("ALTER TABLE inventory.stock_movements ADD CONSTRAINT stock_movements_actor_type CHECK (actor_type IN ('STAFF', 'CUSTOMER', 'GUEST', 'INTEGRATION', 'SYSTEM'))");

        // Written once and never changed (§1.6), as the audit log. OR REPLACE: migrate:fresh drops
        // tables but not functions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION inventory.movements_are_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'inventory.stock_movements is append-only: % is not allowed', TG_OP;
            END;
            $$;

            CREATE TRIGGER stock_movements_append_only
                BEFORE UPDATE OR DELETE ON inventory.stock_movements
                FOR EACH ROW EXECUTE FUNCTION inventory.movements_are_append_only();

            -- TRUNCATE skips row triggers, so it gets its own.
            CREATE TRIGGER stock_movements_no_truncate
                BEFORE TRUNCATE ON inventory.stock_movements
                FOR EACH STATEMENT EXECUTE FUNCTION inventory.movements_are_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory.stock_movements');
        Schema::dropIfExists('inventory.store_products');
        Schema::dropIfExists('inventory.stock');
        DB::unprepared('DROP FUNCTION IF EXISTS inventory.movements_are_append_only()');
    }
};
