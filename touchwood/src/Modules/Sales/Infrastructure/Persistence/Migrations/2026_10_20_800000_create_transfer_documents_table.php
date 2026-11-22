<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| sales.md §5, §1.9: the bank-transfer documents a company uploads on its order — up to three, private
| files of Platform's media, kept while the order is: Platform refuses to delete one an order holds.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales.transfer_documents', function (Blueprint $table) {
            $table->ulid('order_id');
            $table->ulid('store_id');
            $table->ulid('media_id');
            $table->smallInteger('position');
            $table->timestampTz('uploaded_at');

            $table->primary(['order_id', 'position'], 'transfer_documents_pkey');
            $table->foreign(['order_id', 'store_id'], 'transfer_documents_order')->references(['id', 'store_id'])->on('sales.orders')->restrictOnDelete();
            $table->foreign('media_id', 'transfer_documents_media')->references('id')->on('platform.media')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE sales.transfer_documents ADD CONSTRAINT transfer_documents_position CHECK (position BETWEEN 1 AND 3)');
        // Platform asks, before deleting a file, whether an order holds it.
        DB::statement('CREATE INDEX transfer_documents_by_media ON sales.transfer_documents (media_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales.transfer_documents');
    }
};
