<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| sales.md §5, §1.4, §1.8: a delivered order's returns — the customer asks, staff accept or refuse, the
| pieces come back, the return completes with its refund. Each machine's rule is a CHECK: every time is set
| exactly in the states that have reached it; a refusal has staff's words; one return of an order refunds
| its shipping. A return's lines name the part (an order line) their pieces come from; up to 3 photos,
| private files of Platform's media, kept while the return is.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales.returns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('order_id');
            $table->ulid('store_id');
            $table->string('status', 16);
            $table->string('reason', 24);
            $table->string('reason_other', 500)->nullable();
            $table->string('staff_reason', 500)->nullable();
            $table->timestampTz('requested_at');
            $table->ulid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->bigInteger('refund_due_minor')->nullable();
            $table->boolean('shipping_refunded')->default(false);

            $table->foreign(['order_id', 'store_id'], 'returns_order')->references(['id', 'store_id'])->on('sales.orders')->restrictOnDelete();
            $table->foreign('decided_by', 'returns_decided_by')->references('id')->on('access.staff_users')->restrictOnDelete();
            $table->unique(['id', 'order_id'], 'returns_of_order');
            $table->index(['order_id'], 'returns_by_order');
            $table->index(['store_id', 'status', 'requested_at'], 'returns_by_store');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sales.returns
                ADD CONSTRAINT returns_status CHECK (status IN ('REQUESTED', 'ACCEPTED', 'REFUSED', 'WITHDRAWN', 'RECEIVED', 'COMPLETED', 'CLOSED')),
                ADD CONSTRAINT returns_reason CHECK (reason IN ('DAMAGED', 'WRONG_ITEM', 'NOT_AS_DESCRIBED', 'CHANGED_MIND', 'OTHER')),
                ADD CONSTRAINT returns_reason_other CHECK ((reason = 'OTHER') = (reason_other IS NOT NULL)),
                ADD CONSTRAINT returns_decided CHECK ((decided_at IS NOT NULL) = (status IN ('ACCEPTED', 'REFUSED', 'RECEIVED', 'COMPLETED', 'CLOSED'))),
                ADD CONSTRAINT returns_decided_paired CHECK ((decided_by IS NULL) = (decided_at IS NULL)),
                ADD CONSTRAINT returns_withdrawn CHECK ((withdrawn_at IS NOT NULL) = (status = 'WITHDRAWN')),
                ADD CONSTRAINT returns_received CHECK ((received_at IS NOT NULL) = (status IN ('RECEIVED', 'COMPLETED'))),
                ADD CONSTRAINT returns_closed CHECK ((closed_at IS NOT NULL) = (status = 'CLOSED')),
                ADD CONSTRAINT returns_completed CHECK ((completed_at IS NOT NULL) = (status = 'COMPLETED')),
                ADD CONSTRAINT returns_refund_due CHECK ((refund_due_minor IS NOT NULL) = (status = 'COMPLETED') AND COALESCE(refund_due_minor >= 0, true)),
                ADD CONSTRAINT returns_refused CHECK (status <> 'REFUSED' OR staff_reason IS NOT NULL),
                ADD CONSTRAINT returns_shipping_refunded CHECK (NOT shipping_refunded OR status = 'COMPLETED')
            SQL);
        DB::statement('CREATE UNIQUE INDEX returns_one_shipping_refund ON sales.returns (order_id) WHERE shipping_refunded');

        Schema::create('sales.return_lines', function (Blueprint $table) {
            $table->ulid('return_id');
            $table->ulid('order_id');
            $table->ulid('order_line_id');
            $table->integer('quantity');
            $table->integer('received_quantity')->nullable();
            $table->integer('restocked_quantity')->nullable();

            $table->primary(['return_id', 'order_line_id'], 'return_lines_pkey');
            $table->foreign(['return_id', 'order_id'], 'return_lines_return')->references(['id', 'order_id'])->on('sales.returns')->cascadeOnDelete();
            $table->foreign(['order_line_id', 'order_id'], 'return_lines_order_line')->references(['id', 'order_id'])->on('sales.order_lines')->restrictOnDelete();
            $table->index(['order_line_id'], 'return_lines_by_order_line');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sales.return_lines
                ADD CONSTRAINT return_lines_quantity CHECK (quantity >= 1),
                ADD CONSTRAINT return_lines_received_paired CHECK ((received_quantity IS NULL) = (restocked_quantity IS NULL)),
                ADD CONSTRAINT return_lines_received CHECK (received_quantity BETWEEN 0 AND quantity),
                ADD CONSTRAINT return_lines_restocked CHECK (restocked_quantity BETWEEN 0 AND received_quantity)
            SQL);

        Schema::create('sales.return_photos', function (Blueprint $table) {
            $table->ulid('return_id');
            $table->ulid('media_id');
            $table->smallInteger('position');

            $table->primary(['return_id', 'position'], 'return_photos_pkey');
            $table->foreign('return_id', 'return_photos_return')->references('id')->on('sales.returns')->cascadeOnDelete();
            $table->foreign('media_id', 'return_photos_media')->references('id')->on('platform.media')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE sales.return_photos ADD CONSTRAINT return_photos_position CHECK (position BETWEEN 1 AND 3)');
        // Platform asks, before deleting a file, whether a return holds it.
        DB::statement('CREATE INDEX return_photos_by_media ON sales.return_photos (media_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales.return_photos');
        Schema::dropIfExists('sales.return_lines');
        Schema::dropIfExists('sales.returns');
    }
};
