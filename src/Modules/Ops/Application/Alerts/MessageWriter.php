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
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Dto\OrderMessageDto;
use Shared\Domain\ValueObject\Money;

/**
 * Writes one message in one language (ops.md §1.1), in the approved words at `ops::messages.{kind}`,
 * from what the module says **now** (§2.3): the event names the moment, the module's read gives its
 * details. Null when there is nothing to send any more - the order, return, entry or question is gone,
 * or the rule of the kind no longer holds:
 *
 * - C16: the question is still answered (feedback.md §2.5: events are kept in order per order only);
 * - C17, C17b: still not published, **with a reason** - no reason, no message (feedback.md:75).
 *
 * Amounts are written with the currency's letters (its abbreviation, never its sign), Latin digits always (owner, 2026-10-06):
 * the new riyal and dirham signs are in few fonts an email client has. A link is a path inside the site:
 * the customer's page in the shop, in their language; the panel's page for staff (§1.6: an alert links
 * to its page and never copies details that may change).
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
    ) {}

    /**
     * @param  string  $name  the person's first name
     */
    public function write(EventMessage $message, StoreDto $store, string $locale, string $name): ?MessageText
    {
        $locale = $locale === 'ar' ? 'ar' : 'en';
        $values = ['name' => $name, 'store' => $store->name->in($locale)];

        return match ($message->kind) {
            MessageKind::OrderTaken, MessageKind::OrderChanged => $this->orderWithSummary($message, $store, $locale, $values),
            MessageKind::OrderApproved, MessageKind::OrderCancelled, MessageKind::OrderCameBack, MessageKind::OrderRejected,
            MessageKind::OrderShipped, MessageKind::OrderDelivered => $this->order($message, $store, $locale, $values),
            MessageKind::ReturnAccepted, MessageKind::ReturnRefused, MessageKind::ReturnReceived, MessageKind::ReturnCompleted => $this->customerReturn($message, $store, $locale, $values),
            MessageKind::PointsEarned, MessageKind::PointsChangedByHand => $this->points($message, $store, $locale, $values),
            MessageKind::PointsExpiring => $this->text($message->kind, $locale, [...$values, 'points' => (string) $message->int('points'), 'date' => $message->string('date')], self::shop($store, $locale, 'account/points')),
            MessageKind::QuestionAnswered, MessageKind::QuestionNotPublished => $this->question($message, $store, $locale, $values),
            MessageKind::ReviewNotPublished => $this->review($message, $store, $locale, $values),
            MessageKind::NewOrder => $this->newOrder($message, $locale, $values),
            MessageKind::CompanyApplication => $this->text($message->kind, $locale, $values, '/admin/companies/'.$message->string('companyId')),
            MessageKind::LowStock => $this->text($message->kind, $locale, [...$values, 'low' => (string) $message->int('low'), 'out' => (string) $message->int('out')], '/admin/low-stock'),
            MessageKind::SyncProblems => $this->text($message->kind, $locale, $values, '/admin/sync'),
            MessageKind::ReturnRequested => $this->returnRequested($message, $locale, $values),
            MessageKind::GatewayKeyExpiring => $this->text($message->kind, $locale, [...$values, 'days' => (string) $message->int('days'), 'date' => $message->string('date')], null, sms: true),
            MessageKind::GatewayKeyRejected => $this->text($message->kind, $locale, $values, null, sms: true),
            default => throw new LogicException("Ops writes no event message of the kind {$message->kind->value}."),
        };
    }

    /**
     * C1, C6b: the order's own email carries its lines and totals and how it is paid (owner, 2026-10-28).
     *
     * @param  array<string, string>  $values
     */
    private function orderWithSummary(EventMessage $message, StoreDto $store, string $locale, array $values): ?MessageText
    {
        $order = $this->sales->orderForMessage($message->string('orderId'), $locale);

        if ($order === null) {
            return null;
        }

        $text = $this->text($message->kind, $locale, [...$values, 'order' => $order->number, 'amount' => self::money($order->total, $store, $locale)], self::shop($store, $locale, 'account/orders/'.$order->number));

        return new MessageText($text->title, $text->line, $text->subject, $text->lines, $text->link, $text->action, $this->summary($order, $store, $locale));
    }

    /** @param  array<string, string> $values */
    private function order(EventMessage $message, StoreDto $store, string $locale, array $values): ?MessageText
    {
        $order = $this->sales->orderForMessage($message->string('orderId'), $locale);

        if ($order === null) {
            return null;
        }

        $values['order'] = $order->number;
        $optional = [];

        if ($message->kind === MessageKind::OrderRejected) {
            $optional['reason'] = $order->rejectionReason;
        }

        if ($message->kind === MessageKind::OrderShipped) {
            $values['carrier'] = $order->carrier ?? '';
            $optional['tracking_number'] = $order->trackingNumber;
        }

        if ($message->kind === MessageKind::OrderDelivered) {
            $values['days'] = (string) (int) $this->platform->setting(self::RETURN_WINDOW, $store->storeId())->value;
        }

        return $this->text($message->kind, $locale, $values, self::shop($store, $locale, 'account/orders/'.$order->number), $optional);
    }

    /** @param  array<string, string> $values */
    private function customerReturn(EventMessage $message, StoreDto $store, string $locale, array $values): ?MessageText
    {
        $return = $this->sales->returnForMessage($message->string('returnId'));

        if ($return === null) {
            return null;
        }

        $values['order'] = $return->orderNumber;
        $optional = [];

        if ($message->kind === MessageKind::ReturnRefused) {
            $optional['reason'] = $return->staffReason;
        }

        if ($message->kind === MessageKind::ReturnCompleted) {
            if ($return->refundDue === null) {
                return null;
            }

            $values['amount'] = self::money($return->refundDue, $store, $locale);
        }

        return $this->text($message->kind, $locale, $values, self::shop($store, $locale, 'account/returns/'.$return->returnId), $optional);
    }

    /** @param  array<string, string> $values */
    private function points(EventMessage $message, StoreDto $store, string $locale, array $values): ?MessageText
    {
        $entry = $this->loyalty->entryForMessage($message->int('entryId'));

        if ($entry === null) {
            return null;
        }

        $optional = [];

        if ($message->kind === MessageKind::PointsEarned) {
            $values['points'] = (string) $entry->points;
            $values['order'] = $entry->orderNumber ?? '';
        } else {
            // Signed, so "changed by +50" and "by -20" say which way.
            $values['points'] = ($entry->points > 0 ? '+' : '').$entry->points;
            $optional['reason'] = $entry->reason;
        }

        return $this->text($message->kind, $locale, $values, self::shop($store, $locale, 'account/points'), $optional);
    }

    /** @param  array<string, string> $values */
    private function question(EventMessage $message, StoreDto $store, string $locale, array $values): ?MessageText
    {
        $question = $this->feedback->questionForMessage($message->string('questionId'));

        if ($question === null) {
            return null;
        }

        if ($message->kind === MessageKind::QuestionAnswered && $question->status !== QuestionStatus::Answered) {
            return null;
        }

        if ($message->kind === MessageKind::QuestionNotPublished && (! in_array($question->status, [QuestionStatus::Rejected, QuestionStatus::Unpublished], true) || self::blank($question->reason))) {
            return null;
        }

        $product = $this->productName($question->productId, $locale);

        if ($product === null) {
            return null;
        }

        return $this->text($message->kind, $locale, [...$values, 'product' => $product, 'reason' => (string) $question->reason], self::shop($store, $locale, 'account/questions'));
    }

    /** @param  array<string, string> $values */
    private function review(EventMessage $message, StoreDto $store, string $locale, array $values): ?MessageText
    {
        $review = $this->feedback->reviewForMessage($message->string('reviewId'));

        if ($review === null || ! in_array($review->status, [ReviewStatus::Rejected, ReviewStatus::Unpublished], true) || self::blank($review->reason)) {
            return null;
        }

        $product = $this->productName($review->productId, $locale);

        if ($product === null) {
            return null;
        }

        return $this->text($message->kind, $locale, [...$values, 'product' => $product, 'reason' => (string) $review->reason], self::shop($store, $locale, 'account/reviews'));
    }

    /** @param  array<string, string> $values */
    private function newOrder(EventMessage $message, string $locale, array $values): ?MessageText
    {
        $order = $this->sales->orderForMessage($message->string('orderId'), $locale);

        return $order === null ? null : $this->text($message->kind, $locale, [...$values, 'order' => $order->number], '/admin/orders/'.$order->orderId);
    }

    /** @param  array<string, string> $values */
    private function returnRequested(EventMessage $message, string $locale, array $values): ?MessageText
    {
        $return = $this->sales->returnForMessage($message->string('returnId'));

        return $return === null ? null : $this->text($message->kind, $locale, [...$values, 'order' => $return->orderNumber], '/admin/returns/'.$return->returnId);
    }

    /**
     * The kind's words with its values; a line under `optional` only when its value exists.
     *
     * @param  array<string, string>  $values
     * @param  array<string, string|null>  $optional
     */
    private function text(MessageKind $kind, string $locale, array $values, ?string $link, array $optional = [], bool $sms = false): MessageText
    {
        $key = "ops::messages.{$kind->value}";
        $lines = array_values(array_map(static fn (mixed $line): string => (string) $line, (array) $this->translator->get("{$key}.lines", $values, $locale)));

        foreach ($optional as $field => $value) {
            if (! self::blank($value)) {
                $lines[] = (string) $this->translator->get("{$key}.optional.{$field}", [...$values, $field => (string) $value], $locale);
            }
        }

        return new MessageText(
            (string) $this->translator->get("{$key}.title", $values, $locale),
            (string) $this->translator->get("{$key}.line", $values, $locale),
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
