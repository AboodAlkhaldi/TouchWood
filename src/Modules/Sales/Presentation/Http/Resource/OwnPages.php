<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MoneyData;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Sales\Application\Command\BuyAgain\BuyAgainSkipped;
use Modules\Sales\Application\Query\ListOwnOrders\ListOwnOrders;
use Modules\Sales\Application\Query\ListOwnOrders\ListOwnOrdersHandler;
use Modules\Sales\Application\Query\Own\OrderLineView;
use Modules\Sales\Application\Query\Own\OwnOrderRow;
use Modules\Sales\Application\Query\Own\OwnReturnRow;
use Modules\Sales\Application\Query\Own\ReturnLineView;
use Modules\Sales\Application\Query\ViewOwnOrder\ViewOwnOrder;
use Modules\Sales\Application\Query\ViewOwnOrder\ViewOwnOrderHandler;
use Modules\Sales\Application\Query\ViewOwnReturn\ViewOwnReturn;
use Modules\Sales\Application\Query\ViewOwnReturn\ViewOwnReturnHandler;
use Modules\Sales\Public\Enums\WayToPay;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * S7–S10 - the customer's own orders and returns in the shop (sales.md §4.1), built from §3.1's own reads: every
 * amount written by the server in the order's own currency (Platform's `MoneyData`), every moment in UTC for the
 * page to show in the store's zone, names and values in the page's language.
 */
final readonly class OwnPages
{
    public function __construct(
        private PlatformApi $platform,
        private B2BApi $b2b,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function orders(?string $after, string $locale, ListOwnOrdersHandler $list): MyOrdersPage
    {
        $page = $list->handle(new ListOwnOrders($after));

        return new MyOrdersPage(array_map(fn (OwnOrderRow $row): OwnOrderRowData => new OwnOrderRowData(
            $row->number, self::moment($row->placedAt), $row->status->value, $this->money($row->total, $row->storeId, $row->exponent, $locale), $row->pieces, $row->shippingPending,
        ), $page->orders), $page->next);
    }

    /**
     * @throws Unauthorized|DomainError
     */
    public function order(string $number, string $locale, ViewOwnOrderHandler $view): OwnOrderPage
    {
        $order = $view->handle(new ViewOwnOrder($number));
        $money = fn (Money $amount): MoneyData => $this->money($amount, $order->storeId, $order->exponent, $locale);
        $some = fn (Money $amount): ?MoneyData => $amount->isZero() ? null : $money($amount);
        $held = $order->paid->minorUnits - $order->refunded->minorUnits;
        $currency = $order->due->currencyCode;
        $bank = $order->wayToPay === WayToPay::BankTransfer ? $this->b2b->bankAccount($order->storeId) : null;

        return new OwnOrderPage(
            $order->number,
            self::moment($order->placedAt),
            $order->status->value,
            $order->payment->value,
            $money(Money::of(max(0, $order->due->minorUnits - $held), $currency)),
            $money(Money::of(max(0, $held - $order->due->minorUnits), $currency)),
            self::lines($order->lines, $money, $locale),
            $money($order->grossSubtotal),
            $some(Money::of(max(0, $order->grossSubtotal->minorUnits - $order->netSubtotal->minorUnits), $currency)),
            $order->couponCode,
            $some($order->couponDiscount),
            $order->pointsUsed,
            $some($order->pointsDiscount),
            $money($order->shipping),
            $money($order->vat),
            $money($order->total),
            self::text($order->address['recipientName'] ?? ''),
            $order->contactPhone,
            self::text($order->address['formatted'] ?? ''),
            $order->wayToPay->value,
            $bank === null ? null : new BankData($bank->iban, $bank->bank, $bank->holder),
            $order->transferDocuments,
            $order->mayUploadTransferDocument,
            $order->shipment,
            $order->shippingDetail?->carrier($locale) ?? $order->carrier,
            $order->trackingNumber,
            self::maybe($order->shippedAt),
            self::maybe($order->deliveredAt),
            self::maybe($order->returnDeadline),
            array_map(fn (OwnReturnRow $row): OwnReturnRowData => new OwnReturnRowData(
                $row->returnId, $row->status, self::moment($row->requestedAt), $row->refundDue === null ? null : $money($row->refundDue), $row->staffReason,
            ), $order->returns),
            $order->mayCancel,
            $order->mayReturn,
            $this->zone($order->storeId),
            $order->shippingDetail?->trackingLink,
            $order->shippingDetail->feePending ?? false,
        );
    }

    /**
     * S9 - the lines that may still be returned, and how many pieces of each.
     *
     * @throws Unauthorized|DomainError
     */
    public function askReturn(string $number, string $locale, ViewOwnOrderHandler $view): AskReturnPage
    {
        $order = $view->handle(new ViewOwnOrder($number));
        $money = fn (Money $amount): MoneyData => $this->money($amount, $order->storeId, $order->exponent, $locale);
        $lines = array_values(array_filter(self::lines($order->lines, $money, $locale), static fn (OwnLineData $line): bool => $line->returnable > 0));

        return new AskReturnPage($order->number, $lines, self::maybe($order->returnDeadline), $this->zone($order->storeId));
    }

    /** The order's id, to act on it: carried by the reads, never shown. */
    public function orderId(string $number, ViewOwnOrderHandler $view): string
    {
        return $view->handle(new ViewOwnOrder($number))->orderId;
    }

    /**
     * @throws Unauthorized|DomainError
     */
    public function returned(string $returnId, string $locale, ViewOwnReturnHandler $view, ViewOwnOrderHandler $orders): OwnReturnPage
    {
        $return = $view->handle(new ViewOwnReturn($returnId));
        $storeId = $orders->handle(new ViewOwnOrder($return->orderNumber))->storeId;
        $en = $locale === 'en';

        return new OwnReturnPage(
            $return->returnId,
            $return->orderNumber,
            $return->status,
            $return->reason->value,
            $return->reasonOther,
            $return->staffReason,
            self::moment($return->requestedAt),
            self::maybe($return->decidedAt),
            self::maybe($return->receivedAt),
            self::maybe($return->completedAt),
            self::maybe($return->withdrawnAt),
            self::maybe($return->closedAt),
            $return->refundDue === null ? null : $this->money($return->refundDue, $storeId, $return->exponent, $locale),
            array_map(static fn (ReturnLineView $line): OwnReturnLineData => new OwnReturnLineData(
                $en ? $line->nameEn : $line->nameAr, self::values($line->values, $locale), $line->code, $line->gift, $line->asked, $line->received,
            ), $return->lines),
            $return->photos,
            $this->zone($storeId),
        );
    }

    /**
     * @param  list<BuyAgainSkipped>  $skipped
     * @return list<array<string, mixed>>
     */
    public static function skipped(array $skipped, string $locale): array
    {
        return array_map(static fn (BuyAgainSkipped $line): array => (new BuyAgainSkippedData(
            $locale === 'en' ? $line->nameEn : $line->nameAr, $line->mode, $line->quantity, $line->reason->value,
        ))->toArray(), $skipped);
    }

    /**
     * @param  list<OrderLineView>  $lines
     * @param  callable(Money): MoneyData  $money
     * @return list<OwnLineData>
     */
    private static function lines(array $lines, callable $money, string $locale): array
    {
        $en = $locale === 'en';
        $rows = array_map(static fn (OrderLineView $line): OwnLineData => new OwnLineData(
            $line->lineId, $en ? $line->nameEn : $line->nameAr, self::values($line->values, $locale), $line->code, $line->mode, $line->gift,
            $line->quantity, $money($line->unit), $money($line->total), $line->returnable,
        ), $lines);

        // The gift last (S8).
        usort($rows, static fn (OwnLineData $a, OwnLineData $b): int => (int) $a->gift <=> (int) $b->gift);

        return $rows;
    }

    /**
     * @param  list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}>  $values
     */
    private static function values(array $values, string $locale): string
    {
        $en = $locale === 'en';

        return implode(' · ', array_map(static fn (array $value): string => ($en ? $value['attributeEn'] : $value['attributeAr']).': '.($en ? $value['valueEn'] : $value['valueAr']), $values));
    }

    /** An amount in the order's currency: the store's sign while the store still sells in it, else its letters. */
    private function money(Money $amount, string $storeId, int $exponent, string $locale): MoneyData
    {
        $store = $this->store($storeId);

        try {
            return $store === null ? throw new InvalidArgumentException('No store.') : MoneyData::of($amount, $store, $locale === 'en' ? 'en' : 'ar');
        } catch (InvalidArgumentException) {
            return new MoneyData($amount->format($exponent).' '.$amount->currencyCode, $amount->minorUnits);
        }
    }

    private function store(string $storeId): ?StoreDto
    {
        return $this->platform->store(StoreId::fromString($storeId));
    }

    private function zone(string $storeId): string
    {
        return $this->store($storeId)->timezone ?? 'UTC';
    }

    private static function moment(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.vP');
    }

    private static function maybe(?DateTimeImmutable $at): ?string
    {
        return $at === null ? null : self::moment($at);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
