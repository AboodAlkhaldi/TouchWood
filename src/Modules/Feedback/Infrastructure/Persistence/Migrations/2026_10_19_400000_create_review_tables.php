<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| feedback.md §5, the reviews' part: one review per customer per product for ever — a rejected one
| keeps its row — written in a store whose staff moderate it, showing the variant bought; and each
| product's rating, kept with every approval and unpublishing. Every rule the code checks first is
| held here too (handoff §5.3).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback.reviews', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('product_id');
            $table->ulid('variant_id');
            $table->ulid('customer_id');
            $table->ulid('store_id');
            $table->smallInteger('rating');
            $table->text('body');
            $table->string('locale', 2);
            $table->string('status', 12);
            $table->string('mark', 20);
            $table->text('reason')->nullable();
            $table->ulid('moderated_by')->nullable();
            $table->timestampTz('moderated_at')->nullable();
            $table->timestampTz('created_at');

            $table->primary('id', 'reviews_pkey');
            $table->unique(['customer_id', 'product_id'], 'reviews_one_per_product');
            $table->foreign('product_id', 'reviews_product')->references('id')->on('catalog.products')->restrictOnDelete();
            $table->foreign('variant_id', 'reviews_variant')->references('id')->on('catalog.variants')->restrictOnDelete();
            $table->foreign('customer_id', 'reviews_customer')->references('id')->on('access.customers')->restrictOnDelete();
            $table->foreign('store_id', 'reviews_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('moderated_by', 'reviews_moderated_by')->references('id')->on('access.staff_users')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE feedback.reviews
                ADD CONSTRAINT reviews_rating CHECK (rating BETWEEN 1 AND 5),
                ADD CONSTRAINT reviews_body CHECK (char_length(body) BETWEEN 1 AND 2000),
                ADD CONSTRAINT reviews_locale CHECK (locale IN ('ar', 'en')),
                ADD CONSTRAINT reviews_status CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED', 'UNPUBLISHED')),
                ADD CONSTRAINT reviews_mark CHECK (mark IN ('VERIFIED_PURCHASE', 'RETURNED')),
                ADD CONSTRAINT reviews_moderation CHECK ((moderated_by IS NULL) = (moderated_at IS NULL)),
                ADD CONSTRAINT reviews_pending CHECK ((status = 'PENDING') = (moderated_at IS NULL)),
                ADD CONSTRAINT reviews_reason CHECK (reason IS NULL OR (status IN ('REJECTED', 'UNPUBLISHED') AND char_length(reason) BETWEEN 1 AND 500))
            SQL);

        DB::statement('CREATE INDEX reviews_by_product ON feedback.reviews (product_id, status, created_at DESC, id)');
        DB::statement('CREATE INDEX reviews_by_store_queue ON feedback.reviews (store_id, status, created_at, id)');
        // "My reviews" walk the unique key (customer_id, product_id); a variant Catalog would delete is
        // looked up by its own index — the foreign key's check and the usage check both ask it.
        DB::statement('CREATE INDEX reviews_by_variant ON feedback.reviews (variant_id)');

        Schema::create('feedback.product_ratings', function (Blueprint $table) {
            $table->ulid('product_id');
            $table->bigInteger('rating_sum');
            $table->integer('rating_count');
            $table->timestampTz('updated_at');

            $table->primary('product_id', 'product_ratings_pkey');
            $table->foreign('product_id', 'product_ratings_product')->references('id')->on('catalog.products')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE feedback.product_ratings ADD CONSTRAINT product_ratings_sum CHECK (rating_count >= 0 AND rating_sum BETWEEN rating_count AND 5 * rating_count)');
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback.product_ratings');
        Schema::dropIfExists('feedback.reviews');
    }
};
