<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| ops.md §1.6 S10c (owner, Content round 3 #20; §9.6 #3): a queued email Brevo refused for everybody tells the Super
| Admins in the bell, as S10 does. A staff kind with the bell alone, so the kind CHECKs of ops.inbox_entries take it,
| and ops.sent_messages does not: it sends nothing. MessageKind refuses any other value first.
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
        'return_requested', 'late_payment_refund_due'
        SQL;

    private const string BELL_ONLY = "'security_email_failed', 'security_sms_failed'";

    public function up(): void
    {
        $this->kinds(self::BELL_ONLY.", 'queued_email_refused'");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM ops.inbox_entries WHERE kind = 'queued_email_refused'");
        $this->kinds(self::BELL_ONLY);
    }

    private function kinds(string $bellOnly): void
    {
        $customer = self::CUSTOMER_KINDS;
        $staff = self::STAFF_KINDS;

        DB::statement(<<<SQL
            ALTER TABLE ops.inbox_entries
                DROP CONSTRAINT inbox_entries_kind,
                DROP CONSTRAINT inbox_entries_kind_person,
                ADD CONSTRAINT inbox_entries_kind CHECK (kind IN ({$customer}, {$staff}, {$bellOnly}, 'offer_notice', 'staff_message')),
                ADD CONSTRAINT inbox_entries_kind_person CHECK ((kind IN ({$staff}, {$bellOnly})) = (staff_id IS NOT NULL))
            SQL);
    }
};
