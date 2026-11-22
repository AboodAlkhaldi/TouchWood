<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| ops.md §9.8 (WhatsApp replaces SMS for codes; access.md amendment 98), §5:
| - ops.sent_messages takes the channel WHATSAPP (`varchar(8)` holds it) and the kind `auto_reply`, and gets an index
|   on `provider_id`, which a later "failed" status from Meta finds the row by (a partial one: the QUEUED and email
|   rows without an id are not in it);
| - ops.whatsapp_auto_replies: who was answered, so a person gets one auto-reply in 24 hours. The sender is only a
|   keyed HMAC of the number (the key from APP_KEY), 64 hex characters: a plain hash of a phone number could be
|   reversed by trying every number. A row goes after 24 hours (PruneWrittenMessages' neighbour, §6); `replied_at` is
|   indexed for that and for the hour's count.
| Each CHECK is the last line: SentMessage and AutoReplyClaims refuse the value first.
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

    public function up(): void
    {
        $this->sentMessages("'EMAIL', 'SMS', 'WHATSAPP'", ", 'auto_reply'");

        DB::statement('CREATE INDEX sent_messages_by_provider_id ON ops.sent_messages (provider_id) WHERE provider_id IS NOT NULL');

        Schema::create('ops.whatsapp_auto_replies', function (Blueprint $table) {
            $table->char('sender_hmac', 64);
            $table->timestampTz('replied_at');

            $table->primary('sender_hmac', 'whatsapp_auto_replies_pkey');
        });

        DB::statement("ALTER TABLE ops.whatsapp_auto_replies ADD CONSTRAINT whatsapp_auto_replies_hmac CHECK (sender_hmac ~ '^[0-9a-f]{64}$')");
        DB::statement('CREATE INDEX whatsapp_auto_replies_by_age ON ops.whatsapp_auto_replies (replied_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ops.whatsapp_auto_replies');
        DB::statement('DROP INDEX IF EXISTS ops.sent_messages_by_provider_id');
        DB::statement("DELETE FROM ops.sent_messages WHERE channel = 'WHATSAPP' OR kind = 'auto_reply'");
        $this->sentMessages("'EMAIL', 'SMS'", '');
    }

    private function sentMessages(string $channels, string $extraKind): void
    {
        $customer = self::CUSTOMER_KINDS;
        $staff = self::STAFF_KINDS;

        DB::statement(<<<SQL
            ALTER TABLE ops.sent_messages
                DROP CONSTRAINT sent_messages_channel,
                DROP CONSTRAINT sent_messages_kind,
                ADD CONSTRAINT sent_messages_channel CHECK (channel IN ({$channels})),
                ADD CONSTRAINT sent_messages_kind CHECK (kind IN ({$customer}, 'security', {$staff}, 'offer_notice', 'staff_message', 'newsletter_confirmation'{$extraKind}))
            SQL);
    }
};
