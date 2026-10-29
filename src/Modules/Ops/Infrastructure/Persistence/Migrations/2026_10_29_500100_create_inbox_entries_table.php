<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| ops.md §1.2, §5: the inbox behind the shop's bell and the panel's bell. One entry is one person's -
| a customer's or a staff member's, never both - in one store, with its title and line in both
| languages, an optional link inside the site, and whether it was read. Entries older than twelve
| months are deleted (§5); a person's entries are deleted when their account is anonymized (§6).
|
| The kinds are MessageKind's that make a bell entry (every one but C18), a customer's kind on a
| customer's entry and a staff kind on a staff member's. Each CHECK is the last line: InboxEntry
| refuses the value first.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops.inbox_entries', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('customer_id')->nullable();
            $table->ulid('staff_id')->nullable();
            $table->ulid('store_id');
            $table->string('kind', 32);
            $table->text('title_ar');
            $table->text('title_en');
            $table->text('line_ar');
            $table->text('line_en');
            $table->text('link')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at');

            $table->primary('id', 'inbox_entries_pkey');
            $table->foreign('customer_id', 'inbox_entries_customer')->references('id')->on('access.customers')->restrictOnDelete();
            $table->foreign('staff_id', 'inbox_entries_staff')->references('id')->on('access.staff_users')->restrictOnDelete();
            $table->foreign('store_id', 'inbox_entries_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ops.inbox_entries
                ADD CONSTRAINT inbox_entries_person CHECK (num_nonnulls(customer_id, staff_id) = 1),
                ADD CONSTRAINT inbox_entries_kind CHECK (kind IN (
                    'order_taken', 'order_approved', 'order_rejected', 'order_shipped', 'order_delivered',
                    'order_cancelled', 'order_changed', 'order_came_back', 'return_accepted', 'return_refused',
                    'return_received', 'return_completed', 'refund_sent', 'payment_received', 'pay_remaining',
                    'points_earned', 'points_changed_by_hand', 'points_expiring', 'coupon_assigned',
                    'question_answered', 'review_not_published', 'question_not_published',
                    'new_order', 'company_application', 'low_stock', 'sync_problems', 'gateway_key_expiring',
                    'gateway_key_rejected', 'transfer_overdue', 'payment_not_accepted', 'campaign_expiring',
                    'return_requested'
                )),
                ADD CONSTRAINT inbox_entries_kind_person CHECK ((kind IN (
                    'new_order', 'company_application', 'low_stock', 'sync_problems', 'gateway_key_expiring',
                    'gateway_key_rejected', 'transfer_overdue', 'payment_not_accepted', 'campaign_expiring',
                    'return_requested'
                )) = (staff_id IS NOT NULL)),
                ADD CONSTRAINT inbox_entries_title CHECK (char_length(title_ar) BETWEEN 1 AND 200 AND char_length(title_en) BETWEEN 1 AND 200),
                ADD CONSTRAINT inbox_entries_line CHECK (char_length(line_ar) BETWEEN 1 AND 500 AND char_length(line_en) BETWEEN 1 AND 500),
                ADD CONSTRAINT inbox_entries_link CHECK (link IS NULL OR (char_length(link) BETWEEN 1 AND 2000 AND link LIKE '/%' AND link NOT LIKE '//%')),
                ADD CONSTRAINT inbox_entries_read CHECK (read_at IS NULL OR read_at >= created_at)
            SQL);

        // A customer's bell: their entries in the shop's store, newest first, and the unread count.
        DB::statement('CREATE INDEX inbox_entries_by_customer ON ops.inbox_entries (customer_id, store_id, created_at DESC, id DESC) WHERE customer_id IS NOT NULL');
        DB::statement('CREATE INDEX inbox_entries_customer_unread ON ops.inbox_entries (customer_id, store_id) WHERE customer_id IS NOT NULL AND read_at IS NULL');
        // A staff member's bell: their entries over the stores they may view.
        DB::statement('CREATE INDEX inbox_entries_by_staff ON ops.inbox_entries (staff_id, created_at DESC, id DESC) WHERE staff_id IS NOT NULL');
        DB::statement('CREATE INDEX inbox_entries_staff_unread ON ops.inbox_entries (staff_id, store_id) WHERE staff_id IS NOT NULL AND read_at IS NULL');
        // The nightly removal of what is twelve months old.
        DB::statement('CREATE INDEX inbox_entries_by_age ON ops.inbox_entries (created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ops.inbox_entries');
    }
};
