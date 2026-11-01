<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MoneyData;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Sales\Application\Query\ListCarriers\ListCarriers;
use Modules\Sales\Application\Query\ListCarriers\ListCarriersHandler;
use Modules\Sales\Application\Query\ListCustomerOrders\ListCustomerOrders;
use Modules\Sales\Application\Query\ListCustomerOrders\ListCustomerOrdersHandler;
use Modules\Sales\Application\Query\ListOrders\ListOrders;
use Modules\Sales\Application\Query\ListOrders\ListOrdersHandler;
use Modules\Sales\Application\Query\ListOrderStores\ListOrderStores;
use Modules\Sales\Application\Query\ListOrderStores\ListOrderStoresHandler;
use Modules\Sales\Application\Query\ListOrderStores\OrderStoreChoice;
use Modules\Sales\Application\Query\Own\OrderLineView;
use Modules\Sales\Application\Query\Own\OwnReturnRow;
use Modules\Sales\Application\Query\PrintOrder\PrintOrder;
use Modules\Sales\Application\Query\PrintOrder\PrintOrderHandler;
use Modules\Sales\Application\Query\SalesActions\SalesActionsForReader;
use Modules\Sales\Application\Query\Staff\CustomerName;
use Modules\Sales\Application\Query\Staff\EditRow;
use Modules\Sales\Application\Query\Staff\HistoryRow;
use Modules\Sales\Application\Query\Staff\MoneyRow;
use Modules\Sales\Application\Query\Staff\StaffOrderRow;
use Modules\Sales\Application\Query\ViewOrder\ViewOrder;
use Modules\Sales\Application\Query\ViewOrder\ViewOrderHandler;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * P1–P3 - the panel's orders, an order and its packing slip (sales.md §4.1), built from §3.2's staff reads:
 * every amount written by the server in the order's own currency and decimals (Platform's `MoneyData`),
 * every moment in UTC for the page to show in the store's zone, every name in the page's language.
 */
final readonly class OrderPages
{
    /** The tabs §4.1 P1 lists, in its order: Waiting for Approval opened first (R2-25). */
    public const array TABS = ['WAITING_APPROVAL', 'TO_SHIP', 'SHIPPED', 'UNPAID', 'SHORT', 'TRANSFERS_TO_CHECK', 'ALL'];

    public function __construct(
        private Application $app,
        private PlatformApi $platform,
        private ListOrderStoresHandler $stores,
        private SalesActionsForReader $actions,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function list(?string $storeCode, string $tab, ?string $after, ?string $q, ?string $customerId, ListOrdersHandler $list): OrdersPage
    {
        $choices = $this->stores->handle(new ListOrderStores);
        $chosen = null;

        foreach ($choices as $choice) {
            if ($storeCode !== null && strtolower($choice->code) === strtolower($storeCode)) {
                $chosen = $choice;
            }
        }

        if ($storeCode !== null && $chosen === null) {
            abort(404);
        }

        $tab = in_array($tab, self::TABS, true) ? $tab : self::TABS[0];
        // A box too long is cut to what the search reads, never a refused page.
        $q = mb_substr(trim($q ?? ''), 0, 100);
        // One customer's orders: every tab, so "See all" shows them all.
        $tab = $customerId === null ? $tab : 'ALL';
        $page = $list->handle(new ListOrders($chosen?->storeId, $tab, $after, $q, $customerId));
        $codes = self::codes($choices);

        return new OrdersPage(
            array_map(fn (StaffOrderRow $row): OrderRowData => $this->row($row, $codes), $page->orders),
            $page->next,
            $tab,
            $chosen === null ? null : strtolower($chosen->code),
            array_map(fn (OrderStoreChoice $choice): SalesStoreData => new SalesStoreData(strtolower($choice->code), $choice->name->in($this->locale()), $choice->isActive), $choices),
            self::TABS,
            $chosen === null ? null : $this->store($chosen->storeId)?->timezone,
            $q,
            $customerId,
            $customerId === null ? null : ($page->orders[0]->customer->name ?? null),
        );
    }

    /**
     * P7 - the customer's latest orders on the staff customer page, each as the list draws it.
     *
     * @throws Unauthorized
     */
    public function customerSection(string $customerId, ListCustomerOrdersHandler $orders): CustomerOrdersSectionData
    {
        $rows = $orders->handle(new ListCustomerOrders($customerId));
        $codes = self::codes($this->stores->handle(new ListOrderStores));

        return new CustomerOrdersSectionData(strtolower($customerId), array_map(fn (StaffOrderRow $row): OrderRowData => $this->row($row, $codes), $rows));
    }

    /**
     * @param  list<OrderStoreChoice>  $choices
     * @return array<string, string> each store's code by its id
     */
    private static function codes(array $choices): array
    {
        $codes = [];

        foreach ($choices as $choice) {
            $codes[$choice->storeId] = strtolower($choice->code);
        }

        return $codes;
    }

    /**
     * @param  array<string, string>  $codes
     */
    private function row(StaffOrderRow $row, array $codes): OrderRowData
    {
        return new OrderRowData(
            $row->orderId,
            $row->number,
            $codes[$row->storeId] ?? '',
            self::moment($row->placedAt),
            self::customer($row->customer),
            $row->status,
            $row->shipment,
            $row->payment->value,
            $this->money($row->total, $row->storeId, $row->exponent),
            $row->shortOfStock,
            $row->shippingFeePending,
        );
    }

    /**
     * @throws Unauthorized
     */
    public function order(string $orderId, ViewOrderHandler $view, ListCarriersHandler $carriers): OrderPage
    {
        $order = $view->handle(new ViewOrder($orderId));
        $store = $this->store($order->storeId);
        $money = fn (Money $amount): MoneyData => $this->money($amount, $order->storeId, $order->exponent);
        $held = $order->paid->minorUnits - $order->refunded->minorUnits;
        $owed = max(0, $order->due->minorUnits - $held);
        $refundDue = max(0, $held - $order->due->minorUnits);
        $actions = $this->actions->forStore(StoreId::fromString($order->storeId));

        return new OrderPage(
            $order->orderId,
            $order->number,
            new SalesStoreData(strtolower($store->code ?? ''), $store?->name->in($this->locale()) ?? '', $store->isActive ?? false),
            self::moment($order->placedAt),
            self::customer($order->customer),
            $order->contactName,
            $order->contactEmail,
            $order->contactPhone,
            $order->audience,
            $order->status,
            $order->shipment,
            $order->payment->value,
            $order->customerStatus->value,
            $order->wayToPay->value,
            $order->mayShipUnpaid,
            self::maybe($order->shortOfStockAt),
            array_map(fn (OrderLineView $line): OrderLineData => $this->line($line, $money), $order->lines),
            $money($order->grossSubtotal),
            $money($order->netSubtotal),
            $order->couponCode,
            $money($order->couponDiscount),
            $order->pointsUsed,
            $money($order->pointsDiscount),
            $money($order->goodsTotal),
            $money($order->shipping),
            $money($order->vat),
            $money($order->total),
            $money($order->due),
            $money($order->paid),
            $money($order->refunded),
            $money(Money::of($owed, $order->due->currencyCode)),
            $money(Money::of($refundDue, $order->due->currencyCode)),
            $money(Money::of(max(0, $held), $order->due->currencyCode)),
            $money(Money::of(max(0, $order->grossSubtotal->minorUnits - $order->netSubtotal->minorUnits), $order->due->currencyCode)),
            self::text($order->address['recipientName'] ?? ''),
            self::text($order->address['formatted'] ?? ''),
            $order->decidedBy,
            self::maybe($order->decidedAt),
            $order->decisionReason,
            $order->cancelReason,
            $order->cancelOther,
            $order->cancelledBy,
            self::maybe($order->cancelledAt),
            $order->shippingDetail === null ? $order->carrier : ($this->locale() === 'en' ? $order->shippingDetail->carrierEn : $order->shippingDetail->carrierAr),
            $order->trackingNumber,
            self::maybe($order->shippedAt),
            self::maybe($order->deliveredAt),
            self::maybe($order->returnDeadline),
            self::maybe($order->cameBackAt),
            array_map(fn (MoneyRow $row): MoneyRecordData => new MoneyRecordData(
                $row->recordId, $row->kind, $money($row->amount), $row->way, $row->reference, $row->note, $row->happenedOn,
                $row->recordedBy, self::moment($row->recordedAt), $row->voidedBy, self::maybe($row->voidedAt), $row->voidReason,
            ), $order->money),
            array_map(static fn (HistoryRow $row): OrderHistoryData => new OrderHistoryData(
                $row->kind, self::moment($row->at), $row->by, $row->detail,
                is_int($row->detail['amount'] ?? null) ? $money(Money::of($row->detail['amount'], $order->due->currencyCode)) : null,
                is_string($row->detail['way'] ?? null) ? $row->detail['way'] : null,
            ), $order->history),
            array_map(static fn (EditRow $row): OrderEditData => new OrderEditData($row->editId, $row->reason, $row->by, self::moment($row->at)), $order->edits),
            array_map(static fn (array $tick): OrderTickData => new OrderTickData((string) $tick['variantId'], $tick['by'], self::moment($tick['at'])), $order->ticks),
            $order->wired,
            $order->tickable,
            array_map(static fn (OwnReturnRow $row): OrderReturnData => new OrderReturnData($row->returnId, $row->status, self::moment($row->requestedAt), $row->refundDue === null ? null : $money($row->refundDue), $row->staffReason), $order->returns),
            array_map(static fn (array $file): OrderFileData => new OrderFileData((string) $file['mediaId'], (int) $file['position'], self::moment($file['uploadedAt'])), $order->transferDocuments),
            new OrderActionsData($actions->mayApprove, $actions->mayEdit, $actions->mayCancel, $actions->mayFulfil, $actions->mayRecordMoney, $actions->mayManageReturns),
            $store->timezone ?? 'UTC',
            $order->exponent,
            self::decimal($owed, $order->exponent),
            $actions->mayFulfil ? $this->carriers($order->storeId, $order->address, $carriers) : [],
            $order->shippingDetail?->carrierId,
            $order->shippingDetail?->trackingLink,
            $this->locale() === 'en' ? $order->shippingDetail?->orderedCarrierEn : $order->shippingDetail?->orderedCarrierAr,
            $order->shippingDetail->feePending ?? false,
            OrderBoxData::of($order->box),
            $order->shippingDetail?->orderedCarrierId,
        );
    }

    /**
     * @param  array<string, mixed>  $address  the order's snapshot: its country first
     * @return list<OrderCarrierData>
     */
    private function carriers(string $storeId, array $address, ListCarriersHandler $carriers): array
    {
        try {
            $list = $carriers->handle(new ListCarriers($storeId, is_string($address['countryCode'] ?? null) ? $address['countryCode'] : null));
        } catch (Unauthorized) {
            return [];
        }

        return OrderCarrierData::list($list, is_string($address['countryCode'] ?? null) ? $address['countryCode'] : null, $this->locale() === 'en');
    }

    /** An amount in its decimals ("248.00"), as Record Payment's `shown` reads it. */
    private static function decimal(int $minor, int $exponent): string
    {
        $digits = str_pad((string) $minor, $exponent + 1, '0', STR_PAD_LEFT);

        return $exponent === 0 ? $digits : substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);
    }

    /**
     * @throws Unauthorized
     */
    public function print(string $orderId, PrintOrderHandler $print): PrintPage
    {
        $order = $print->handle(new PrintOrder($orderId));
        $store = $this->store($order->storeId);
        $money = fn (Money $amount): MoneyData => $this->money($amount, $order->storeId, $order->exponent);

        return new PrintPage(
            $orderId,
            $order->number,
            $store?->name->in($this->locale()) ?? '',
            self::moment($order->placedAt),
            $order->contactName,
            $order->contactPhone,
            self::text($order->address['recipientName'] ?? ''),
            self::text($order->address['formatted'] ?? ''),
            array_map(fn (OrderLineView $line): OrderLineData => $this->line($line, $money), $order->lines),
            $money($order->grossSubtotal),
            $money($order->netSubtotal),
            $money(Money::of(max(0, $order->grossSubtotal->minorUnits - $order->netSubtotal->minorUnits), $order->total->currencyCode)),
            $money($order->couponDiscount),
            $money($order->pointsDiscount),
            $money($order->goodsTotal),
            $money($order->shipping),
            $money($order->vat),
            $order->vatRateBasisPoints,
            $money($order->total),
            $order->wayToPay->value,
            $store->timezone ?? 'UTC',
            OrderBoxData::of($order->box),
            $order->shippingFeePending,
        );
    }

    /**
     * @param  callable(Money): MoneyData  $money
     */
    private function line(OrderLineView $line, callable $money): OrderLineData
    {
        $en = $this->locale() === 'en';
        $values = array_map(static fn (array $value): string => ($en ? $value['attributeEn'] : $value['attributeAr']).': '.($en ? $value['valueEn'] : $value['valueAr']), $line->values);

        return new OrderLineData(
            $line->lineId,
            $line->variantId,
            $en ? $line->nameEn : $line->nameAr,
            implode(' · ', $values),
            $line->code,
            $line->mode,
            $line->gift,
            $line->quantity,
            $money($line->unit),
            $money($line->listUnit),
            $money($line->total),
        );
    }

    /** An amount in the order's currency: the store's sign while the store still sells in it, else its letters. */
    private function money(Money $amount, string $storeId, int $exponent): MoneyData
    {
        $store = $this->store($storeId);

        try {
            return $store === null ? throw new InvalidArgumentException('No store.') : MoneyData::of($amount, $store, $this->locale());
        } catch (InvalidArgumentException) {
            return new MoneyData($amount->format($exponent).' '.$amount->currencyCode, $amount->minorUnits);
        }
    }

    private function store(string $storeId): ?StoreDto
    {
        return $this->platform->store(StoreId::fromString($storeId));
    }

    private static function customer(CustomerName $customer): OrderCustomerData
    {
        return new OrderCustomerData($customer->customerId, $customer->name, $customer->deleted);
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

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
