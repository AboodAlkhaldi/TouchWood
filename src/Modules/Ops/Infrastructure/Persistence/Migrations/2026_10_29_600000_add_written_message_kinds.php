<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| ops.md §1.7, §1.8, §1.9 (step 7): three kinds join MessageKind - an offer notice and a staff member's
| message, which make a bell entry and an email, and the newsletter's confirmation, an email only. The
| kind CHECKs of ops.inbox_entries and ops.sent_messages are replaced with the lists that hold them;
| neither is a staff alert, so the person CHECK is unchanged. MessageKind refuses any other value first.
*/

return new class extends Migration
{
    private const string CUSTOMER_KINDS = <<<'SQL'
        'order_taken', 'order_approved', 'order_rejected', 'order_shipped', 'order_delivered',
        'order_cancelled', 'order_changed', 'order_came_back', 'return_accepted', 'return_refused',
        'return_received', 'return_completed', 'refund_sent', 'payment_received', 'pay_remaining',
        'points_earned', 'points_changed_by_hand', 'points_expiring', 'coupon_assigned',
        'question_answered', 'review_not_published', 'question_not_published'
        SQL;

    private const string STAFF_KINDS = <<<'SQL'
        'new_order', 'company_application', 'low_stock', 'sync_problems', 'gateway_key_expiring',
        'gateway_key_rejected', 'transfer_overdue', 'payment_not_accepted', 'campaign_expiring',
        'return_requested'
        SQL;

    public function up(): void
    {
        $customer = self::CUSTOMER_KINDS;
        $staff = self::STAFF_KINDS;

        DB::statement(<<<SQL
            ALTER TABLE ops.inbox_entries
                DROP CONSTRAINT inbox_entries_kind,
                ADD CONSTRAINT inbox_entries_kind CHECK (kind IN ({$customer}, {$staff}, 'offer_notice', 'staff_message'))
            SQL);
        DB::statement(<<<SQL
            ALTER TABLE ops.sent_messages
                DROP CONSTRAINT sent_messages_kind,
                ADD CONSTRAINT sent_messages_kind CHECK (kind IN ({$customer}, 'security', {$staff}, 'offer_notice', 'staff_message', 'newsletter_confirmation'))
            SQL);
    }

    public function down(): void
    {
        $customer = self::CUSTOMER_KINDS;
        $staff = self::STAFF_KINDS;

        DB::statement("DELETE FROM ops.inbox_entries WHERE kind IN ('offer_notice', 'staff_message')");
        DB::statement("DELETE FROM ops.sent_messages WHERE kind IN ('offer_notice', 'staff_message', 'newsletter_confirmation')");
        DB::statement("ALTER TABLE ops.inbox_entries DROP CONSTRAINT inbox_entries_kind, ADD CONSTRAINT inbox_entries_kind CHECK (kind IN ({$customer}, {$staff}))");
        DB::statement("ALTER TABLE ops.sent_messages DROP CONSTRAINT sent_messages_kind, ADD CONSTRAINT sent_messages_kind CHECK (kind IN ({$customer}, 'security', {$staff}))");
    }
};
