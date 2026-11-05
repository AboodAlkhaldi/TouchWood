<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| ops.md §1.6 S7b (the owner, 2026-11-04): the alert that a payment succeeded after its try was released, a staff kind
| with a bell and an email, so the kind CHECKs of both ops.inbox_entries and ops.sent_messages take it.
| MessageKind refuses any other value first.
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
        $this->kinds(self::STAFF_KINDS.", 'late_payment_refund_due'");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM ops.inbox_entries WHERE kind = 'late_payment_refund_due'");
        DB::statement("DELETE FROM ops.sent_messages WHERE kind = 'late_payment_refund_due'");
        $this->kinds(self::STAFF_KINDS);
    }

    private function kinds(string $staff): void
    {
        $customer = self::CUSTOMER_KINDS;
        $bellOnly = "'security_email_failed', 'security_sms_failed'";

        DB::statement(<<<SQL
            ALTER TABLE ops.inbox_entries
                DROP CONSTRAINT inbox_entries_kind,
                DROP CONSTRAINT inbox_entries_kind_person,
                ADD CONSTRAINT inbox_entries_kind CHECK (kind IN ({$customer}, {$staff}, {$bellOnly}, 'offer_notice', 'staff_message')),
                ADD CONSTRAINT inbox_entries_kind_person CHECK ((kind IN ({$staff}, {$bellOnly})) = (staff_id IS NOT NULL))
            SQL);
        DB::statement(<<<SQL
            ALTER TABLE ops.sent_messages
                DROP CONSTRAINT sent_messages_kind,
                ADD CONSTRAINT sent_messages_kind CHECK (kind IN ({$customer}, 'security', {$staff}, 'offer_notice', 'staff_message', 'newsletter_confirmation'))
            SQL);
    }
};
