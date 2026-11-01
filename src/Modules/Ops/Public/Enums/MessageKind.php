<?php

declare(strict_types=1);

namespace Modules\Ops\Public\Enums;

/**
 * Every kind of message the system sends (ops.md §1.5 C1–C18, §1.6 S1–S10), with the channels the spec
 * fixes for it - never switchable per store (owner, round 1 #3). A staff member's own switches
 * (Access) still choose bell and email per topic; that is decided when an alert is sent, not here.
 *
 * Its wording, in Arabic and English, is at `ops::messages.{value}` (§1.1). C8 is two kinds, one for
 * each decision; S8 (campaign expiry) waits for Content.
 *
 * Three more are not rows of §1.5 or §1.6 (step 7): an offer notice (§1.7) and a staff member's
 * message (§1.8), whose title and text are the staff's own in both languages, and the email that asks
 * a guest to confirm their newsletter sign-up (§1.9), which has no bell.
 */
enum MessageKind: string
{
    // Messages to customers (§1.5): bell and email, except C18.
    case OrderTaken = 'order_taken';
    case OrderApproved = 'order_approved';
    case OrderRejected = 'order_rejected';
    case OrderShipped = 'order_shipped';
    case OrderDelivered = 'order_delivered';
    case OrderCancelled = 'order_cancelled';
    case OrderChanged = 'order_changed';
    case OrderCameBack = 'order_came_back';
    case ReturnAccepted = 'return_accepted';
    case ReturnRefused = 'return_refused';
    case ReturnReceived = 'return_received';
    case ReturnCompleted = 'return_completed';
    case RefundSent = 'refund_sent';
    case PaymentReceived = 'payment_received';
    case PayRemaining = 'pay_remaining';
    case PointsEarned = 'points_earned';
    case PointsChangedByHand = 'points_changed_by_hand';
    case PointsExpiring = 'points_expiring';
    case CouponAssigned = 'coupon_assigned';
    case QuestionAnswered = 'question_answered';
    case ReviewNotPublished = 'review_not_published';
    case QuestionNotPublished = 'question_not_published';
    case Security = 'security';

    // Alerts to staff (§1.6): bell and email; SMS only where the spec says.
    case NewOrder = 'new_order';
    case CompanyApplication = 'company_application';
    case LowStock = 'low_stock';
    case SyncProblems = 'sync_problems';
    case GatewayKeyExpiring = 'gateway_key_expiring';
    case GatewayKeyRejected = 'gateway_key_rejected';
    case TransferOverdue = 'transfer_overdue';
    case PaymentNotAccepted = 'payment_not_accepted';
    case CampaignExpiring = 'campaign_expiring';
    case ReturnRequested = 'return_requested';
    case SecurityEmailsFailing = 'security_emails_failing';

    // Written by staff (§1.7, §1.8), and the newsletter's double opt-in (§1.9).
    case OfferNotice = 'offer_notice';
    case StaffMessage = 'staff_message';
    case NewsletterConfirmation = 'newsletter_confirmation';

    /** The row of the spec it is (ops.md §1.5, §1.6). */
    public function code(): string
    {
        return match ($this) {
            self::OrderTaken => 'C1',
            self::OrderApproved => 'C2',
            self::OrderRejected => 'C3',
            self::OrderShipped => 'C4',
            self::OrderDelivered => 'C5',
            self::OrderCancelled => 'C6',
            self::OrderChanged => 'C6b',
            self::OrderCameBack => 'C7',
            self::ReturnAccepted, self::ReturnRefused => 'C8',
            self::ReturnReceived => 'C8b',
            self::ReturnCompleted => 'C9',
            self::RefundSent => 'C9b',
            self::PaymentReceived => 'C10',
            self::PayRemaining => 'C11',
            self::PointsEarned => 'C12',
            self::PointsChangedByHand => 'C13',
            self::PointsExpiring => 'C14',
            self::CouponAssigned => 'C15',
            self::QuestionAnswered => 'C16',
            self::ReviewNotPublished => 'C17',
            self::QuestionNotPublished => 'C17b',
            self::Security => 'C18',
            self::NewOrder => 'S1',
            self::CompanyApplication => 'S2',
            self::LowStock => 'S3',
            self::SyncProblems => 'S4',
            self::GatewayKeyExpiring => 'S5',
            self::GatewayKeyRejected => 'S5b',
            self::TransferOverdue => 'S6',
            self::PaymentNotAccepted => 'S7',
            self::CampaignExpiring => 'S8',
            self::ReturnRequested => 'S9',
            self::SecurityEmailsFailing => 'S10',
            self::OfferNotice => '§1.7',
            self::StaffMessage => '§1.8',
            self::NewsletterConfirmation => '§1.9',
        };
    }

    /** An alert to staff (§1.6), else a message to a customer (§1.5). */
    public function isForStaff(): bool
    {
        return str_starts_with($this->code(), 'S');
    }

    /**
     * The channels the spec fixes for it.
     *
     * - C18: codes by SMS, links by email, **no bell entry** (§1.5).
     * - S5: the critical SMS goes **only at 1 day** before the key expires, to super admins who left
     *   their switch on (§1.6; access.md amendment 81) - the 30- and 7-day alerts are bell and email.
     * - S5b: the critical SMS to super admins (§9.3 #12).
     * - The newsletter's confirmation: an email to a guest, who has no bell (§1.9).
     * - S10: the bell alone. Email is what is failing, and the critical SMS is the gateway key's (owner,
     *   2026-11-01).
     *
     * @return list<MessageChannel>
     */
    public function channels(): array
    {
        return match ($this) {
            self::Security => [MessageChannel::Email, MessageChannel::Sms],
            self::NewsletterConfirmation => [MessageChannel::Email],
            self::SecurityEmailsFailing => [MessageChannel::Bell],
            self::GatewayKeyExpiring, self::GatewayKeyRejected => [MessageChannel::Bell, MessageChannel::Email, MessageChannel::Sms],
            default => [MessageChannel::Bell, MessageChannel::Email],
        };
    }

    /** Whether it makes an entry in the person's inbox (§1.2). */
    public function hasBell(): bool
    {
        return in_array(MessageChannel::Bell, $this->channels(), true);
    }
}
