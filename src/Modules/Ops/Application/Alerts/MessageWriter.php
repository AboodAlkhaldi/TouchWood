<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Alerts;

use Illuminate\Contracts\Translation\Translator;
use LogicException;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Feedback\Public\Contracts\FeedbackApi;
use Modules\Feedback\Public\Enums\QuestionStatus;
use Modules\Feedback\Public\Enums\ReviewStatus;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Dto\OrderMessageDto;
use Psr\Log\LoggerInterface;
use Shared\Domain\ValueObject\Money;

/**
 * Writes one message in one language (ops.md §1.1), in the approved words at `ops::messages.{kind}`,
 * from what the module says **now** (§2.3): the event names the moment, the module's read gives its
 * details. Null when there is nothing to send any more - the order, return, entry or question is gone,
 * or the rule of the kind no longer holds:
 *
 * - C2: the order is still approved;
 * - C8 accepted, S9: the return was not withdrawn since;
 * - C16: the question is still answered (feedback.md §2.5: events are kept in order per order only);
 * - C17, C17b: still not published, **with a reason** - no reason, no message (feedback.md:75);
 * - C9b, C10: the record is the kind's and was not voided since (owner, 2026-10-29);
 * - C15: the coupon was not deleted since.
 *
 * **A read that names another store, or another customer, than the event did sends nothing** - it is
 * logged with its ids only, never a name or an address: a message never reaches the wrong person.
 *
 * C1 shows the recipient and the delivery address in the email only, never the bell (owner, 2026-10-31); an
 * order with no address has no such line, never a stand-in. C4 names the carrier only when the order has one,
 * and offers Track only when it holds a tracking link.
 *
 * Amounts are written with the currency's letters (its abbreviation, never its sign), Latin digits
 * always (owner, 2026-10-06): the new riyal and dirham signs are in few fonts an email client has. A
 * link is a path inside the site: the customer's page in the shop, in their language; the panel's page
 * for staff (§1.6: an alert links to its page and never copies details that may change).
 */
final readonly class MessageWriter
{
    /** The store's return window (sales.md §1.10), read through Platform's settings. */
    private const string RETURN_WINDOW = 'sales.returns.window_days';

    public function __construct(
        private Translator $translator,
        private PlatformApi $platform,
        private SalesReports $sales,
        private LoyaltyApi $loyalty,
        private FeedbackApi $feedback,
        private CatalogApi $catalog,
        private PromotionsApi $promotions,
        private LoggerInterface $log,
    ) {}

    /**
     * @param  string  $name  the person's first name
     * @param  string|null  $customerId  the customer the event named; none for a staff alert
     */
    public function write(EventMessage $message, StoreDto $store, string $locale, string $name, ?string $customerId = null): ?MessageText
    {
        $locale = $locale === 'ar' ? 'ar' : 'en';
        $values = ['name' => $name, 'store' => $store->name->in($locale)];
        $to = new MessageTarget($message, $store, $customerId);

        return match ($message->kind) {
            MessageKind::OrderTaken, MessageKind::OrderChanged => $this->orderWithSummary($to, $locale, $values),
            MessageKind::OrderApproved, MessageKind::OrderCancelled, MessageKind::OrderCameBack, MessageKind::OrderRejected,
            MessageKind::OrderShipped, MessageKind::OrderDelivered => $this->order($to, $locale, $values),
            MessageKind::ReturnAccepted, MessageKind::ReturnRefused, MessageKind::ReturnReceived, MessageKind::ReturnCompleted => $this->customerReturn($to, $locale, $values),
            MessageKind::RefundSent, MessageKind::PaymentReceived => $this->moneyRecord($to, $locale, $values),
            MessageKind::CouponAssigned => $this->coupon($to, $locale, $values),
            MessageKind::PointsEarned, MessageKind::PointsChangedByHand => $this->points($to, $locale, $values),
            MessageKind::PointsExpiring => $this->text($message->kind, $locale, [...$values, 'points' => (string) $message->int('points'), 'date' => $message->string('date')], self::shop($store, $locale, 'account/points')),
            MessageKind::QuestionAnswered, MessageKind::QuestionNotPublished => $this->question($to, $locale, $values),
            MessageKind::ReviewNotPublished => $this->review($to, $locale, $values),
            MessageKind::NewOrder => $this->newOrder($to, $locale, $values),
            MessageKind::CompanyApplication => $this->text($message->kind, $locale, $values, '/admin/companies/'.$message->string('companyId')),
            MessageKind::LowStock => $this->text($message->kind, $locale, [...$values, 'low' => (string) $message->int('low'), 'out' => (string) $message->int('out')], '/admin/low-stock'),
            MessageKind::SyncProblems => $this->text($message->kind, $locale, $values, '/admin/sync'),
            MessageKind::ReturnRequested => $this->returnRequested($to, $locale, $values),
            MessageKind::GatewayKeyExpiring => $this->text($message->kind, $locale, [...$values, 'days' => (string) $message->int('days'), 'date' => $message->string('date')], null, sms: true),
            MessageKind::GatewayKeyRejected => $this->text($message->kind, $locale, $values, null, sms: true),
            default => throw new LogicException("Ops writes no event message of the kind {$message->kind->value}."),
        };
    }

    /**
     * C1, C6b: the order's own email carries its lines and totals and how it is paid (owner, 2026-10-28); C1
     * where it goes too (owner, 2026-10-31).
     *
     * @param  array<string, string>  $values
     */
    private function orderWithSummary(MessageTarget $to, string $locale, array $values): ?MessageText
    {
        $order = $this->sales->orderForMessage($to->message->string('orderId'), $locale);

        if ($order === null || ! $this->belongs($to, $order->storeId, $order->customerId)) {
            return null;
        }

        $kind = $to->message->kind;
        $values = [...$values, 'order' => $order->number, 'amount' => self::money($order->total, $to->store, $locale), 'recipient' => $order->recipientName];
        $optional = $kind === MessageKind::OrderTaken ? ['address' => $order->deliveryAddress] : [];
        $text = $this->text($kind, $locale, $values, self::shop($to->store, $locale, 'account/orders/'.$order->number), $optional);

        return new MessageText($text->title, $text->line, $text->subject, $text->lines, $text->link, $text->action, $this->summary($order, $to->store, $locale));
    }

    /** @param  array<string, string> $values */
    private function order(MessageTarget $to, string $locale, array $values): ?MessageText
    {
        $kind = $to->message->kind;
        $order = $this->sales->orderForMessage($to->message->string('orderId'), $locale);

        if ($order === null || ! $this->belongs($to, $order->storeId, $order->customerId)) {
            return null;
        }

        if ($kind === MessageKind::OrderApproved && $order->status !== 'APPROVED') {
            return null;
        }

        $values['order'] = $order->number;
        $optional = [];
        $words = null;

        if ($kind === MessageKind::OrderRejected) {
            $optional['reason'] = $order->rejectionReason;
        }

        if ($kind === MessageKind::OrderShipped) {
            $values['carrier'] = trim((string) $order->carrier);
            $words = $values['carrier'] === '' ? 'without_carrier' : null;
            $optional['tracking_number'] = $order->trackingNumber;
        }

        if ($kind === MessageKind::OrderDelivered) {
            $values['days'] = (string) (int) $this->platform->setting(self::RETURN_WINDOW, $to->store->storeId())->value;
        }

        $text = $this->text($kind, $locale, $values, self::shop($to->store, $locale, 'account/orders/'.$order->number), $optional, words: $words);

        // C4: the number's line stays whether or not there is a link; "Track" only when a link is stored (§1.5).
        return $kind === MessageKind::OrderShipped && ! self::blank($order->trackingLink)
            ? new MessageText($text->title, $text->line, $text->subject, $text->lines, $text->link, $text->action, trackLink: $order->trackingLink, trackAction: (string) $this->translator->get('ops::messages.order_shipped.optional.track', [], $locale))
            : $text;
    }

    /** @param  array<string, string> $values */
    private function customerReturn(MessageTarget $to, string $locale, array $values): ?MessageText
    {
        $kind = $to->message->kind;
        $return = $this->sales->returnForMessage($to->message->string('returnId'));

        if ($return === null || ! $this->belongs($to, $return->storeId, $return->customerId)) {
            return null;
        }

        if ($kind === MessageKind::ReturnAccepted && $return->status === 'WITHDRAWN') {
            return null;
        }

        $values['order'] = $return->orderNumber;
        $optional = [];

        if ($kind === MessageKind::ReturnRefused) {
            $optional['reason'] = $return->staffReason;
        }

        if ($kind === MessageKind::ReturnCompleted) {
            if ($return->refundDue === null) {
                return null;
            }

            $values['amount'] = self::money($return->refundDue, $to->store, $locale);
        }

        return $this->text($kind, $locale, $values, self::shop($to->store, $locale, 'account/returns/'.$return->returnId), $optional);
    }

    /** @param  array<string, string> $values */
    private function moneyRecord(MessageTarget $to, string $locale, array $values): ?MessageText
    {
        $kind = $to->message->kind;
        $record = $this->sales->moneyRecordForMessage($to->message->string('moneyRecordId'));

        if ($record === null || $record->voided || ! $this->belongs($to, $record->storeId, $record->customerId)) {
            return null;
        }

        if ($record->kind !== ($kind === MessageKind::RefundSent ? 'REFUND' : 'PAYMENT')) {
            return null;
        }

        $values['order'] = $record->orderNumber;
        $values['amount'] = self::money($record->amount, $to->store, $locale);

        if ($kind === MessageKind::RefundSent) {
            $values['way'] = (string) $this->translator->get("ops::messages.refund_sent.ways.{$record->way}", [], $locale);
        }

        return $this->text($kind, $locale, $values, self::shop($to->store, $locale, 'account/orders/'.$record->orderNumber));
    }

    /** @param  array<string, string> $values */
    private function coupon(MessageTarget $to, string $locale, array $values): ?MessageText
    {
        $code = $this->promotions->couponCode($to->message->string('couponId'));

        return $code === null ? null : $this->text($to->message->kind, $locale, [...$values, 'code' => $code], self::shop($to->store, $locale, 'account/coupons'));
    }

    /** @param  array<string, string> $values */
    private function points(MessageTarget $to, string $locale, array $values): ?MessageText
    {
        $entry = $this->loyalty->entryForMessage($to->message->int('entryId'));

        if ($entry === null || ! $this->belongs($to, $entry->storeId, $entry->customerId)) {
            return null;
        }

        $optional = [];

        if ($to->message->kind === MessageKind::PointsEarned) {
            $values['points'] = (string) $entry->points;
            $values['order'] = $entry->orderNumber ?? '';
        } else {
            // Signed, so "changed by +50" and "by -20" say which way.
            $values['points'] = ($entry->points > 0 ? '+' : '').$entry->points;
            $optional['reason'] = $entry->reason;
        }

        return $this->text($to->message->kind, $locale, $values, self::shop($to->store, $locale, 'account/points'), $optional);
    }

    /** @param  array<string, string> $values */
    private function question(MessageTarget $to, string $locale, array $values): ?MessageText
    {
        $kind = $to->message->kind;
        $question = $this->feedback->questionForMessage($to->message->string('questionId'));

        if ($question === null || ! $this->belongs($to, $question->storeId, $question->customerId)) {
            return null;
        }

        if ($kind === MessageKind::QuestionAnswered && $question->status !== QuestionStatus::Answered) {
            return null;
        }

        if ($kind === MessageKind::QuestionNotPublished && (! in_array($question->status, [QuestionStatus::Rejected, QuestionStatus::Unpublished], true) || self::blank($question->reason))) {
            return null;
        }

        $product = $this->productName($question->productId, $locale);

        if ($product === null) {
            return null;
        }

        return $this->text($kind, $locale, [...$values, 'product' => $product, 'reason' => (string) $question->reason], self::shop($to->store, $locale, 'account/questions'));
    }

    /** @param  array<string, string> $values */
    private function review(MessageTarget $to, string $locale, array $values): ?MessageText
    {
        $review = $this->feedback->reviewForMessage($to->message->string('reviewId'));

        if ($review === null || ! $this->belongs($to, $review->storeId, $review->customerId)) {
            return null;
        }

        if (! in_array($review->status, [ReviewStatus::Rejected, ReviewStatus::Unpublished], true) || self::blank($review->reason)) {
            return null;
        }

        $product = $this->productName($review->productId, $locale);

        if ($product === null) {
            return null;
        }

        return $this->text($to->message->kind, $locale, [...$values, 'product' => $product, 'reason' => (string) $review->reason], self::shop($to->store, $locale, 'account/reviews'));
    }

    /** @param  array<string, string> $values */
    private function newOrder(MessageTarget $to, string $locale, array $values): ?MessageText
    {
        $order = $this->sales->orderForMessage($to->message->string('orderId'), $locale);

        if ($order === null || ! $this->belongs($to, $order->storeId, $order->customerId)) {
            return null;
        }

        return $this->text($to->message->kind, $locale, [...$values, 'order' => $order->number], '/admin/orders/'.$order->orderId);
    }

    /** @param  array<string, string> $values */
    private function returnRequested(MessageTarget $to, string $locale, array $values): ?MessageText
    {
        $return = $this->sales->returnForMessage($to->message->string('returnId'));

        if ($return === null || ! $this->belongs($to, $return->storeId, $return->customerId) || $return->status === 'WITHDRAWN') {
            return null;
        }

        return $this->text($to->message->kind, $locale, [...$values, 'order' => $return->orderNumber], '/admin/returns/'.$return->returnId);
    }

    /**
     * Whether the read is about the event's store and, for a customer's message, the event's customer.
     * Anything else is logged by ids only, and nothing is sent.
     */
    private function belongs(MessageTarget $to, string $readStoreId, string $readCustomerId): bool
    {
        $sameStore = strtolower($readStoreId) === strtolower($to->store->id);
        $sameCustomer = $to->customerId === null || strtolower($readCustomerId) === strtolower($to->customerId);

        if (! $sameStore || ! $sameCustomer) {
            $this->log->warning('Ops sent no message: the module\'s read names another store or customer than the event.', [
                'kind' => $to->message->kind->value,
                'event_store_id' => strtolower($to->store->id),
                'read_store_id' => strtolower($readStoreId),
                'event_customer_id' => $to->customerId === null ? null : strtolower($to->customerId),
                'read_customer_id' => strtolower($readCustomerId),
            ]);
        }

        return $sameStore && $sameCustomer;
    }

    /**
     * The kind's words with its values; a line under `optional` only when its value exists. `words`
     * names another line and lines of the kind - C4's without a carrier.
     *
     * @param  array<string, string>  $values
     * @param  array<string, string|null>  $optional
     */
    private function text(MessageKind $kind, string $locale, array $values, ?string $link, array $optional = [], bool $sms = false, ?string $words = null): MessageText
    {
        $key = "ops::messages.{$kind->value}";
        $own = $words === null ? $key : "{$key}.{$words}";
        $lines = array_values(array_map(static fn (mixed $line): string => (string) $line, (array) $this->translator->get("{$own}.lines", $values, $locale)));

        foreach ($optional as $field => $value) {
            if (! self::blank($value)) {
                $lines[] = (string) $this->translator->get("{$key}.optional.{$field}", [...$values, $field => (string) $value], $locale);
            }
        }

        return new MessageText(
            (string) $this->translator->get("{$key}.title", $values, $locale),
            (string) $this->translator->get("{$own}.line", $values, $locale),
            (string) $this->translator->get("{$key}.subject", $values, $locale),
            $lines,
            $link,
            $link === null ? null : (string) $this->translator->get('ops::messages.open', [], $locale),
            sms: $sms ? (string) $this->translator->get("{$key}.sms", $values, $locale) : null,
        );
    }

    /**
     * The order's lines as sold or edited, then its totals and how it is paid; the coupon and points rows
     * only when they took something off.
     *
     * @return list<array{label: string, value: string}>
     */
    private function summary(OrderMessageDto $order, StoreDto $store, string $locale): array
    {
        $word = fn (string $key): string => (string) $this->translator->get("ops::messages.summary.{$key}", [], $locale);
        $rows = [];

        foreach ($order->lines as $line) {
            $values = implode(' - ', array_map(static fn (array $value): string => $value['value'], $line->values));
            $label = $line->name.($values === '' ? '' : " ({$values})").' x '.$line->quantity;
            $rows[] = ['label' => $line->gift ? $label.' - '.$word('gift') : $label, 'value' => self::money($line->total, $store, $locale)];
        }

        $rows[] = ['label' => $word('subtotal'), 'value' => self::money($order->subtotal, $store, $locale)];

        if (! $order->couponDiscount->isZero()) {
            $rows[] = ['label' => $word('coupon'), 'value' => '-'.self::money($order->couponDiscount, $store, $locale)];
        }

        if (! $order->pointsDiscount->isZero()) {
            $rows[] = ['label' => $word('points'), 'value' => '-'.self::money($order->pointsDiscount, $store, $locale)];
        }

        $rows[] = ['label' => $word('shipping'), 'value' => self::money($order->shipping, $store, $locale)];
        $rows[] = ['label' => $word('vat'), 'value' => self::money($order->vat, $store, $locale)];
        $rows[] = ['label' => $word('total'), 'value' => self::money($order->total, $store, $locale)];
        $rows[] = ['label' => $word('way_to_pay'), 'value' => (string) $this->translator->get('ops::messages.summary.ways.'.$order->wayToPay, [], $locale)];

        return $rows;
    }

    /** The product's name in the language, the Arabic one where it has no English; null for none. */
    private function productName(string $productId, string $locale): ?string
    {
        $product = $this->catalog->product($productId);

        return $product === null ? null : ($locale === 'en' && $product->nameEn !== null && $product->nameEn !== '' ? $product->nameEn : $product->nameAr);
    }

    private static function money(Money $amount, StoreDto $store, string $locale): string
    {
        return $amount->format($store->currencyExponent).' '.$store->currencyAbbreviation->in($locale);
    }

    /** A page of the shop, in the store and the language: "/{store}/{locale}/account/orders/{number}". */
    private static function shop(StoreDto $store, string $locale, string $page): string
    {
        return '/'.$store->code.'/'.$locale.'/'.$page;
    }

    private static function blank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
