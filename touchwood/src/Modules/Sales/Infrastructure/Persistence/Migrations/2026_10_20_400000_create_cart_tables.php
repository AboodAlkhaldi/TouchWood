<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| sales.md §5, the cart's part (§1.1): one cart a buyer a store — a customer's or a guest's — its lines
| a size, its sale mode and a quantity, the same size and mode once; the coupon typed, a customer's
| only; the lines that left at sign-in, with why (`notice`). A size Catalog deletes leaves the carts.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales.carts', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->ulid('customer_id')->nullable();
            $table->ulid('guest_id')->nullable();
            $table->string('coupon_code', 64)->nullable();
            $table->jsonb('notice')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'carts_pkey');
            $table->foreign('store_id', 'carts_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('customer_id', 'carts_customer')->references('id')->on('access.customers')->cascadeOnDelete();
            $table->unique(['store_id', 'customer_id'], 'carts_one_per_customer');
            $table->unique(['store_id', 'guest_id'], 'carts_one_per_guest');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sales.carts
                ADD CONSTRAINT carts_buyer CHECK (num_nonnulls(customer_id, guest_id) = 1),
                ADD CONSTRAINT carts_coupon_customer CHECK (customer_id IS NOT NULL OR coupon_code IS NULL),
                ADD CONSTRAINT carts_coupon_code CHECK (coupon_code IS NULL OR char_length(coupon_code) BETWEEN 1 AND 64)
            SQL);

        DB::statement('CREATE INDEX carts_by_guest ON sales.carts (guest_id) WHERE guest_id IS NOT NULL');
        DB::statement('CREATE INDEX carts_by_customer ON sales.carts (customer_id) WHERE customer_id IS NOT NULL');
        // The nightly emptying of carts left alone for 90 days.
        DB::statement('CREATE INDEX carts_by_updated ON sales.carts (updated_at)');

        Schema::create('sales.cart_lines', function (Blueprint $table) {
            $table->ulid('cart_id');
            $table->ulid('variant_id');
            $table->string('sale_mode', 9);
            $table->integer('quantity');
            $table->timestampTz('added_at');

            $table->primary(['cart_id', 'variant_id', 'sale_mode'], 'cart_lines_pkey');
            $table->foreign('cart_id', 'cart_lines_cart')->references('id')->on('sales.carts')->cascadeOnDelete();
            $table->foreign('variant_id', 'cart_lines_variant')->references('id')->on('catalog.variants')->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sales.cart_lines
                ADD CONSTRAINT cart_lines_sale_mode CHECK (sale_mode IN ('RETAIL', 'WHOLESALE')),
                ADD CONSTRAINT cart_lines_quantity CHECK (quantity BETWEEN 1 AND 1000000)
            SQL);

        DB::statement('CREATE INDEX cart_lines_by_variant ON sales.cart_lines (variant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales.cart_lines');
        Schema::dropIfExists('sales.carts');
    }
};
