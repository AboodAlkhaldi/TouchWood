<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Alerts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Translation\Translator;
use LogicException;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Content\Public\Contracts\ContentApi;
use Modules\Feedback\Public\Contracts\FeedbackApi;
use Modules\Feedback\Public\Enums\QuestionStatus;
use Modules\Feedback\Public\Enums\ReviewStatus;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Payments\Public\Contracts\PaymentsApi;
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
 * - C11: the event's request is still the order's open one, and the order was not ended since;
 * - C15: the coupon was not deleted since;
 * - S6: the order was not ended since;
 * - S8: Content still knows the campaign.
 *
 * **A read that names another store, or another customer, than the event did sends nothing** - it is
 * logged with its ids only, never a name or an address: a message never reaches the wrong person.
 *
 * C1 shows the recipient and the delivery address in the email only, never the bell (owner, 2026-10-31); an
 * order with no address has no such line, never a stand-in. C4 names the carrier only when the order has one,
 * and offers Track only when it holds a tracking link. S5 and S5b say which key and never that payments stop:
 * a key of the mode not in use stops nothing.
 *
 * **A test order says so** (payments.md §1.6): the messages that speak of its money - C1, C6b, C9b, C10, C11 - carry the
 * mark in the bell's line and under the email's greeting; it says no more than that, for a test order's money may be real
 * when it is paid by transfer. The others have its number alone, `TEST-0001`.
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

    /** An order that owes nothing and can be asked nothing (sales.md §9.9 #5; §1.4: nothing is due on it). */
    private const array ENDED = ['CANCELLED', 'REJECTED', 'UNDELIVERED'];

    public function __construct(
        private Translator $translator,
        private PlatformApi $platform,
        private SalesReports $sales,
        private LoyaltyApi $loyalty,
        private FeedbackApi $feedback,
        private CatalogApi $catalog,
        private PromotionsApi $promotions,
        private ContentApi $content,
        private PaymentsApi $payments,
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
            MessageKind::PayRemaining => $this->payRemaining($to, $locale, $values),
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
            MessageKind::GatewayKeyExpiring => $this->text($message->kind, $locale, [...$values, 'days' => $this->days($message->int('days'), $locale), 'date' => $message->string('date'), 'mode' => $this->keyMode($message, $locale)], self::gatewayPage($store), sms: true),
            MessageKind::GatewayKeyRejected => $this->text($message->kind, $locale, [...$values, 'mode' => $this->keyMode($message, $locale)], self::gatewayPage($store), sms: true),
            MessageKind::TransferOverdue => $this->transferOverdue($to, $locale, $values),
            MessageKind::PaymentNotAccepted => $this->text($message->kind, $locale, $values, null),
            MessageKind::CampaignExpiring => $this->campaignExpiring($to, $locale, $values),
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
        // An online order exists only once its money came (sales.md §9.5 #4): C1 is its one email (§1.5).
        $optional = $kind === MessageKind::OrderTaken ? ['paid' => $order->wayToPay === 'ONLINE' ? $values['amount'] : null, 'address' => $order->deliveryAddress] : [];
        $text = $this->text($kind, $locale, $values, self::shop($to->store, $locale, 'account/orders/'.$order->number), $optional);
        $text = new MessageText($text->title, $text->line, $text->subject, $text->lines, $text->link, $text->action, $this->summary($order, $to->store, $locale));

        return $order->isTest ? $this->testMarked($text, $locale) : $text;
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
            $values['days'] = $this->days((int) $this->platform->setting(self::RETURN_WINDOW, $to->store->storeId())->value, $locale);
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

        $text = $this->text($kind, $locale, $values, self::shop($to->store, $locale, 'account/orders/'.$record->orderNumber));

        return $record->isTest ? $this->testMarked($text, $locale) : $text;
    }

    /**
     * C11: the amount the open request asks, and its page, with the kind's own button. Read through `openRequests`,
     * which writes nothing: a request withdrawn, paid or past its time, or a newer one in its place, sends nothing,
     * when the bell is written and again when the email leaves. The 60 minutes are in the words (payments.md §1.5).
     *
     * @param  array<string, string>  $values
     */
    private function payRemaining(MessageTarget $to, string $locale, array $values): ?MessageText
    {
        $orderId = $to->message->string('orderId');
        $order = $this->sales->orderForMessage($orderId, $locale);

        if ($order === null || ! $this->belongs($to, $order->storeId, $order->customerId) || in_array($order->status, self::ENDED, true)) {
            return null;
        }

        $request = $this->payments->openRequests([$orderId])[$orderId] ?? null;

        if ($request === null || $request->requestId !== $to->message->string('requestId')) {
            return null;
        }

        $kind = $to->message->kind;
        $text = $this->text($kind, $locale, [...$values, 'order' => $order->number, 'amount' => self::money($request->amount, $to->store, $locale)], self::shop($to->store, $locale, 'orders/'.$order->number.'/pay'));

        $text = new MessageText($text->title, $text->line, $text->subject, $text->lines, $text->link, (string) $this->translator->get("ops::messages.{$kind->value}.action", [], $locale));

        return $order->isTest ? $this->testMarked($text, $locale) : $text;
    }

    /**
     * S6: the store's own check time in words, and the order's page, where staff check the receipt (`AlertLinks` takes
     * the link off for whoever cannot open it). Sales fires it once per order, and never for a test order.
     *
     * @param  array<string, string>  $values
     */
    private function transferOverdue(MessageTarget $to, string $locale, array $values): ?MessageText
    {
        $order = $this->sales->orderForMessage($to->message->string('orderId'), $locale);

        if ($order === null || ! $this->belongs($to, $order->storeId, $order->customerId) || in_array($order->status, self::ENDED, true)) {
            return null;
        }

        return $this->text($to->message->kind, $locale, [...$values, 'order' => $order->number, 'hours' => $this->hours($this->payments->transferCheckHours($to->store->storeId()), $locale)], '/admin/orders/'.$order->orderId);
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
     * S8: the campaign by its name, its last day in the store's own zone. No link: Content has no page yet.
     *
     * @param  array<string, string>  $values
     */
    private function campaignExpiring(MessageTarget $to, string $locale, array $values): ?MessageText
    {
        $campaign = $this->content->campaignForMessage($to->message->string('campaignId'));

        if ($campaign === null || ! $this->belongs($to, $campaign->storeId, '')) {
            return null;
        }

        $date = CarbonImmutable::parse($to->message->string('endsAt'))->setTimezone($to->store->timezone)->format('Y-m-d');

        return $this->text($to->message->kind, $locale, [...$values, 'campaign' => $campaign->name->in($locale), 'date' => $date], null);
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

    /** The message with the test mark: after the bell's line, and under the email's greeting. */
    private function testMarked(MessageText $text, string $locale): MessageText
    {
        $mark = (string) $this->translator->get('ops::messages.test_mark', [], $locale);
        $lines = $text->lines;
        array_splice($lines, 1, 0, [$mark]);

        return new MessageText($text->title, $text->line.' '.$mark, $text->subject, $lines, $text->link, $text->action, $text->summary, $text->sms, $text->trackLink, $text->trackAction);
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

    /** A number of days as the language counts them, in Latin digits: "1 day", "7 أيام". */
    private function days(int $days, string $locale): string
    {
        return $this->translator->choice('ops::messages.day_count', $days, [], $locale);
    }

    /** A number of hours as the language counts them, in Latin digits: "1 hour", "72 ساعة". */
    private function hours(int $hours, string $locale): string
    {
        return $this->translator->choice('ops::messages.hour_count', $hours, [], $locale);
    }

    /** Which of the store's two gateway keys: the test or the live one. */
    private function keyMode(EventMessage $message, string $locale): string
    {
        return (string) $this->translator->get('ops::messages.key_modes.'.$message->string('mode'), [], $locale);
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

    /** The Payments Gateway page of the alert's own store (§4.2; owner, 2026-11-02): S5, S5b. Their SMS stays without it. */
    private static function gatewayPage(StoreDto $store): string
    {
        return '/admin/payments/gateway?store='.$store->code;
    }

    private static function blank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
