<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MoneyData;
use Modules\Sales\Application\Query\ListOrderStores\ListOrderStores;
use Modules\Sales\Application\Query\ListOrderStores\ListOrderStoresHandler;
use Modules\Sales\Application\Query\ListOrderStores\OrderStoreChoice;
use Modules\Sales\Application\Query\ListReturns\ListReturns;
use Modules\Sales\Application\Query\ListReturns\ListReturnsHandler;
use Modules\Sales\Application\Query\PreviewReturnRefund\PreviewReturnRefund;
use Modules\Sales\Application\Query\PreviewReturnRefund\PreviewReturnRefundHandler;
use Modules\Sales\Application\Query\SalesActions\SalesActionsForReader;
use Modules\Sales\Application\Query\Staff\CustomerName;
use Modules\Sales\Application\Query\Staff\StaffReturnRow;
use Modules\Sales\Application\Query\ViewReturn\ViewReturn;
use Modules\Sales\Application\Query\ViewReturn\ViewReturnHandler;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * P5, P6 - the panel's returns and a return (sales.md §4.1), built from §3.2's staff reads: the refund written by
 * the server in the order's own currency, every moment in UTC, names in the page's language.
 */
final readonly class ReturnPages
{
    /** The tabs §4.1 P5 lists: one state each, then Closed - refused, withdrawn and closed together (§4.2 B11). */
    public const array TABS = ['REQUESTED', 'ACCEPTED', 'RECEIVED', 'COMPLETED', 'CLOSED_ALL'];

    public function __construct(
        private Application $app,
        private PlatformApi $platform,
        private ListOrderStoresHandler $stores,
        private SalesActionsForReader $actions,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function list(?string $storeCode, string $tab, ?string $after, ListReturnsHandler $list): ReturnsPage
    {
        $choices = $this->stores->handle(new ListOrderStores);
        $chosen = null;
        $codes = [];

        foreach ($choices as $choice) {
            $codes[$choice->storeId] = strtolower($choice->code);

            if ($storeCode !== null && strtolower($choice->code) === strtolower($storeCode)) {
                $chosen = $choice;
            }
        }

        if ($storeCode !== null && $chosen === null) {
            abort(404);
        }

        $tab = in_array($tab, self::TABS, true) ? $tab : self::TABS[0];
        $page = $list->handle(new ListReturns($chosen?->storeId, $tab, $after));

        return new ReturnsPage(
            array_map(fn (StaffReturnRow $row): ReturnRowData => new ReturnRowData(
                $row->returnId,
                $row->orderId,
                $row->orderNumber,
                $codes[$row->storeId] ?? '',
                self::customer($row->customer),
                $row->status,
                self::moment($row->requestedAt),
                $row->refundDue === null ? null : $this->money($row->refundDue, $row->storeId, $row->exponent),
            ), $page->returns),
            $page->next,
            $tab,
            $chosen === null ? null : strtolower($chosen->code),
            array_map(fn (OrderStoreChoice $choice): SalesStoreData => new SalesStoreData(strtolower($choice->code), $choice->name->in($this->locale()), $choice->isActive), $choices),
            self::TABS,
            $chosen === null ? null : $this->platform->store(StoreId::fromString($chosen->storeId))?->timezone,
        );
    }

    /**
     * @throws Unauthorized
     */
    public function view(string $returnId, ViewReturnHandler $view, PreviewReturnRefundHandler $previews): ReturnPage
    {
        $return = $view->handle(new ViewReturn($returnId));
        $store = $this->platform->store(StoreId::fromString($return->storeId));
        $actions = $this->actions->forStore(StoreId::fromString($return->storeId));
        $en = $this->locale() === 'en';

        return new ReturnPage(
            $return->returnId,
            $return->orderId,
            $return->orderNumber,
            new SalesStoreData(strtolower($store->code ?? ''), $store?->name->in($this->locale()) ?? '', $store->isActive ?? false),
            self::customer($return->customer),
            $return->status,
            $return->reason->value,
            $return->reasonOther,
            $return->staffReason,
            self::moment($return->requestedAt),
            $return->decidedBy,
            self::maybe($return->decidedAt),
            self::maybe($return->withdrawnAt),
            self::maybe($return->receivedAt),
            self::maybe($return->closedAt),
            self::maybe($return->completedAt),
            $return->refundDue === null ? null : $this->money($return->refundDue, $return->storeId, $return->exponent),
            $return->shippingRefunded,
            array_map(static function (array $line) use ($en): ReturnLineData {
                /** @var list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}> $values */
                $values = $line['values'];

                return new ReturnLineData(
                    (string) $line['orderLineId'],
                    (string) ($en ? $line['nameEn'] : $line['nameAr']),
                    implode(' · ', array_map(static fn (array $value): string => ($en ? $value['attributeEn'] : $value['attributeAr']).': '.($en ? $value['valueEn'] : $value['valueAr']), $values)),
                    (string) $line['code'],
                    (bool) $line['gift'],
                    (int) $line['asked'],
                    $line['received'] === null ? null : (int) $line['received'],
                    $line['restocked'] === null ? null : (int) $line['restocked'],
                );
            }, $return->lines),
            array_map(static fn (array $photo): ReturnPhotoData => new ReturnPhotoData((string) $photo['mediaId'], (int) $photo['position']), $return->photos),
            $actions->mayManageReturns,
            $actions->mayRecordMoney,
            $return->refundDue === null ? null : self::decimal($return->refundDue->minorUnits, $return->exponent),
            $store->timezone ?? 'UTC',
            $return->exponent,
            $return->status === 'RECEIVED' && $actions->mayManageReturns ? $this->preview($returnId, $return->storeId, $previews) : null,
        );
    }

    private function preview(string $returnId, string $storeId, PreviewReturnRefundHandler $previews): ReturnPreviewData
    {
        $preview = $previews->handle(new PreviewReturnRefund($returnId));
        $money = fn (Money $amount): MoneyData => $this->money($amount, $storeId, $preview->exponent);

        return new ReturnPreviewData($money($preview->goods), $money($preview->vat), $money($preview->shipping), $money($preview->total));
    }

    /** The refund in its decimals, as Record Refund reads it ("120.00"), to fill the dialog with. */
    private static function decimal(int $minor, int $exponent): string
    {
        $digits = str_pad((string) $minor, $exponent + 1, '0', STR_PAD_LEFT);

        return $exponent === 0 ? $digits : substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);
    }

    private function money(Money $amount, string $storeId, int $exponent): MoneyData
    {
        $store = $this->platform->store(StoreId::fromString($storeId));

        try {
            return $store === null ? throw new InvalidArgumentException('No store.') : MoneyData::of($amount, $store, $this->locale());
        } catch (InvalidArgumentException) {
            return new MoneyData($amount->format($exponent).' '.$amount->currencyCode, $amount->minorUnits);
        }
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

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
