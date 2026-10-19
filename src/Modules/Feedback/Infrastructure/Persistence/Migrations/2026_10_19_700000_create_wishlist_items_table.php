<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| feedback.md §5, the wishlist's part: one list per customer, each item a product saved in a store
| (§1.4). A product Catalog deletes leaves the lists with it — nothing to keep — so its key cascades;
| an archived one stays, shown as unavailable.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback.wishlist_items', function (Blueprint $table) {
            $table->ulid('customer_id');
            $table->ulid('product_id');
            $table->ulid('store_id');
            $table->timestampTz('saved_at');

            $table->primary(['customer_id', 'product_id', 'store_id'], 'wishlist_items_pkey');
            $table->foreign('customer_id', 'wishlist_items_customer')->references('id')->on('access.customers')->restrictOnDelete();
            $table->foreign('product_id', 'wishlist_items_product')->references('id')->on('catalog.products')->cascadeOnDelete();
            $table->foreign('store_id', 'wishlist_items_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        // "Favourited products": a store's counts. A customer's list reads the key, which starts with them.
        DB::statement('CREATE INDEX wishlist_items_by_store ON feedback.wishlist_items (store_id, product_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback.wishlist_items');
    }
};
