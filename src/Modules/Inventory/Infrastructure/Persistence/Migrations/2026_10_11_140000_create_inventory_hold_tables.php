<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| inventory.md §1.4, §1.9, §4.1, §5, step 3: one hold per order, its lines - a bought line and a gift
| line of one size are two - and each return's lines, counted once. A size held by an order is never
| deleted from under it.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory.holds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->ulid('order_id');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('created_at');

            $table->unique('order_id', 'holds_order');
            $table->foreign('store_id', 'holds_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->index('expires_at', 'holds_expires_idx');
        });

        Schema::create('inventory.hold_lines', function (Blueprint $table) {
            $table->ulid('hold_id');
            $table->ulid('variant_id');
            $table->boolean('gift');
            $table->integer('quantity');
            $table->boolean('counts_on_stock');
            $table->integer('shipped')->default(0);
            $table->string('ended', 16)->default('OPEN');
            $table->timestampTz('ended_at')->nullable();

            $table->primary(['hold_id', 'variant_id', 'gift'], 'hold_lines_pkey');
            $table->foreign('hold_id', 'hold_lines_hold')->references('id')->on('inventory.holds')->cascadeOnDelete();
            $table->foreign('variant_id', 'hold_lines_variant')->references('id')->on('catalog.variants')->restrictOnDelete();
            $table->index('variant_id', 'hold_lines_variant_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE inventory.hold_lines
                ADD CONSTRAINT hold_lines_quantity CHECK (quantity >= 1),
                ADD CONSTRAINT hold_lines_shipped CHECK (shipped >= 0 AND shipped <= quantity),
                ADD CONSTRAINT hold_lines_ended CHECK (ended IN ('OPEN', 'SHIPPED', 'REDUCED', 'RELEASED', 'EXPIRED')),
                ADD CONSTRAINT hold_lines_ended_at CHECK ((ended = 'OPEN') = (ended_at IS NULL))
            SQL);

        Schema::create('inventory.return_lines', function (Blueprint $table) {
            $table->string('return_id', 64);
            $table->ulid('variant_id');
            $table->boolean('gift');
            $table->ulid('store_id');
            $table->ulid('order_id');
            $table->integer('quantity');
            $table->timestampTz('created_at');

            $table->primary(['return_id', 'variant_id', 'gift'], 'return_lines_pkey');
            $table->foreign('store_id', 'return_lines_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->index('order_id', 'return_lines_order_idx');
        });

        DB::statement('ALTER TABLE inventory.return_lines ADD CONSTRAINT return_lines_quantity CHECK (quantity >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory.return_lines');
        Schema::dropIfExists('inventory.hold_lines');
        Schema::dropIfExists('inventory.holds');
    }
};
