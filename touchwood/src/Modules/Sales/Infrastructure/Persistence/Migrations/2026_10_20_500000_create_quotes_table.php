<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| sales.md §5, the quote (§1.2): what a checkout was asked — the address, the coupon from the cart,
| whether points were asked and how many (none: as many as allowed — answer 44), the way to pay — and
| what it came to, itemised and never changed (`result`), until it expires. Deleted nightly once expired.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales.quotes', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->ulid('customer_id');
            $table->ulid('address_id');
            $table->string('coupon_code', 64)->nullable();
            $table->boolean('use_points');
            $table->integer('points_asked')->nullable();
            $table->string('way_to_pay', 13);
            $table->jsonb('result');
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at');

            $table->primary('id', 'quotes_pkey');
            $table->foreign('store_id', 'quotes_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('customer_id', 'quotes_customer')->references('id')->on('access.customers')->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sales.quotes
                ADD CONSTRAINT quotes_way_to_pay CHECK (way_to_pay IN ('ONLINE', 'BANK_TRANSFER', 'STAFF_CONTACT')),
                ADD CONSTRAINT quotes_points_asked CHECK (points_asked IS NULL OR (use_points AND points_asked >= 1)),
                ADD CONSTRAINT quotes_coupon_code CHECK (coupon_code IS NULL OR char_length(coupon_code) BETWEEN 1 AND 64),
                ADD CONSTRAINT quotes_expiry CHECK (expires_at > created_at)
            SQL);

        DB::statement('CREATE INDEX quotes_by_customer ON sales.quotes (customer_id)');
        // The nightly deletion of expired quotes.
        DB::statement('CREATE INDEX quotes_by_expiry ON sales.quotes (expires_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales.quotes');
    }
};
