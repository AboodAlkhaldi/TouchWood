<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| ops.md §5: the record of every message sent by email or SMS - its kind, the person (a customer, a
| staff member, or nobody known: a password reset to an email address), the store when there is one,
| the channel, the provider's id, and whether it is queued, sent or failed.
|
| **Never a code or a link** (access.md §2.3), nor the text or the address: there is no column for
| any of them. Rows older than twelve months are deleted (§5). Each CHECK is the last line: SentMessage
| refuses the value first.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops.sent_messages', function (Blueprint $table) {
            $table->ulid('id');
            $table->string('kind', 32);
            $table->ulid('customer_id')->nullable();
            $table->ulid('staff_id')->nullable();
            $table->ulid('store_id')->nullable();
            $table->string('channel', 8);
            $table->string('provider_id', 255)->nullable();
            $table->string('status', 8);
            $table->timestampTz('created_at');

            $table->primary('id', 'sent_messages_pkey');
            $table->foreign('customer_id', 'sent_messages_customer')->references('id')->on('access.customers')->restrictOnDelete();
            $table->foreign('staff_id', 'sent_messages_staff')->references('id')->on('access.staff_users')->restrictOnDelete();
            $table->foreign('store_id', 'sent_messages_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ops.sent_messages
                ADD CONSTRAINT sent_messages_person CHECK (num_nonnulls(customer_id, staff_id) <= 1),
                ADD CONSTRAINT sent_messages_kind CHECK (kind IN (
                    'order_taken', 'order_approved', 'order_rejected', 'order_shipped', 'order_delivered',
                    'order_cancelled', 'order_changed', 'order_came_back', 'return_accepted', 'return_refused',
                    'return_received', 'return_completed', 'refund_sent', 'payment_received', 'pay_remaining',
                    'points_earned', 'points_changed_by_hand', 'points_expiring', 'coupon_assigned',
                    'question_answered', 'review_not_published', 'question_not_published', 'security',
                    'new_order', 'company_application', 'low_stock', 'sync_problems', 'gateway_key_expiring',
                    'gateway_key_rejected', 'transfer_overdue', 'payment_not_accepted', 'campaign_expiring',
                    'return_requested'
                )),
                ADD CONSTRAINT sent_messages_channel CHECK (channel IN ('EMAIL', 'SMS')),
                ADD CONSTRAINT sent_messages_status CHECK (status IN ('QUEUED', 'SENT', 'FAILED')),
                ADD CONSTRAINT sent_messages_provider_id CHECK (provider_id IS NULL OR char_length(provider_id) BETWEEN 1 AND 255)
            SQL);

        DB::statement('CREATE INDEX sent_messages_by_customer ON ops.sent_messages (customer_id) WHERE customer_id IS NOT NULL');
        DB::statement('CREATE INDEX sent_messages_by_staff ON ops.sent_messages (staff_id) WHERE staff_id IS NOT NULL');
        DB::statement('CREATE INDEX sent_messages_by_age ON ops.sent_messages (created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ops.sent_messages');
    }
};
