<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\AddressDto;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MoneyData;
use Modules\Promotions\Public\Dto\GiftOfferDto;
use Modules\Sales\Application\Query\Own\OrderLineView;
use Modules\Sales\Application\Query\PreviewOrderEdit\PreviewOrderEdit;
use Modules\Sales\Application\Query\PreviewOrderEdit\PreviewOrderEditHandler;
use Modules\Sales\Application\Query\SalesActions\SalesActionsForReader;
use Modules\Sales\Application\Query\SearchEditSizes\EditSizeRow;
use Modules\Sales\Application\Query\SearchEditSizes\SearchEditSizes;
use Modules\Sales\Application\Query\SearchEditSizes\SearchEditSizesHandler;
use Modules\Sales\Application\Query\ViewOrder\ViewOrder;
use Modules\Sales\Application\Query\ViewOrder\ViewOrderHandler;
use Modules\Sales\Application\SalesPermissions;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * P4 - editing an order (sales.md §4.1), built from §3.2's reads and the edit's own (§1.7): the page, Add Product's
 * search (§4.2 B7) and the preview - every amount written by the server in the order's currency.
 */
final readonly class EditPages
{
    public function __construct(
        private Application $app,
        private PlatformApi $platform,
        private AccessApi $access,
        private CatalogApi $catalog,
        private SalesActionsForReader $actions,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function page(string $orderId, ViewOrderHandler $view): EditPage
    {
        $order = $view->handle(new ViewOrder($orderId));

        // The page is the edit's: a reader without the job in the order's store is refused it, as the edit would be.
        if (! $this->actions->forStore(StoreId::fromString($order->storeId))->mayEdit) {
            throw new Unauthorized(SalesPermissions::ORDER_EDIT);
        }

        $money = fn (Money $amount): MoneyData => $this->money($amount, $order->storeId, $order->exponent);
        $ticked = array_map(static fn (array $tick): string => (string) $tick['variantId'], $order->ticks);
        $gift = null;
        $lines = [];

        foreach ($order->lines as $line) {
            if ($line->gift) {
                $gift = $this->en() ? $line->nameEn : $line->nameAr;

                continue;
            }

            // An edit that raised a line added a part of it (§1.7): the edit names a size and mode once, all its parts together.
            $key = $line->variantId.'|'.$line->mode;
            $before = $lines[$key] ?? null;
            $lines[$key] = new EditLineData(
                $line->variantId, $line->mode, $this->en() ? $line->nameEn : $line->nameAr, $this->values($line), $line->code,
                ($before->quantity ?? 0) + $line->quantity, $money($line->unit), in_array($line->variantId, $ticked, true),
            );
        }

        $lines = array_values($lines);

        // Another of the customer's addresses in the order's store (§1.7); none of a deleted customer's.
        $addresses = $order->customer->deleted ? [] : $this->access->addresses($order->customer->customerId, $order->storeId);

        return new EditPage(
            $order->orderId,
            $order->number,
            $order->audience,
            $lines,
            $gift,
            array_map(static fn (AddressDto $address): CheckoutAddressData => new CheckoutAddressData(
                $address->id, $address->label, $address->recipientName, $address->formatted, $address->isDefault, $address->isComplete,
            ), $addresses),
            is_string($order->address['formatted'] ?? null) ? $order->address['formatted'] : '',
            $money($order->total),
            $money($order->paid),
            $money($order->grossSubtotal),
            $money($order->shipping),
            $money($order->vat),
            $this->platform->store(StoreId::fromString($order->storeId))->timezone ?? 'UTC',
        );
    }

    /**
     * @return list<EditSizeData>
     *
     * @throws Unauthorized|DomainError
     */
    public function sizes(string $orderId, string $q, SearchEditSizesHandler $search, ViewOrderHandler $view): array
    {
        $storeId = $view->handle(new ViewOrder($orderId))->storeId;

        return array_map(function (EditSizeRow $row) use ($storeId): EditSizeData {
            $money = fn (?Money $amount): ?MoneyData => $amount === null ? null : $this->money($amount, $storeId, $row->exponent);

            return new EditSizeData(
                $row->variantId,
                $this->en() ? $row->productNameEn : $row->productNameAr,
                implode(' · ', array_map(fn (array $value): string => ($this->en() ? $value['attributeEn'] : $value['attributeAr']).': '.($this->en() ? $value['valueEn'] : $value['valueAr']), $row->values)),
                $row->code,
                $money($row->retail['now'] ?? null),
                $money($row->retail['before'] ?? null),
                $money($row->wholesale['now'] ?? null),
                $row->wholesale['minimum'] ?? null,
                $row->available,
            );
        }, $search->handle(new SearchEditSizes($orderId, $q)));
    }

    /**
     * @param  list<array{variantId: string, mode: string, quantity: int}>  $lines
     *
     * @throws Unauthorized|DomainError
     */
    public function preview(string $orderId, array $lines, ?string $addressId, string $gift, PreviewOrderEditHandler $previews, ViewOrderHandler $view): EditPreviewData
    {
        $order = $view->handle(new ViewOrder($orderId));
        $plan = $previews->handle(new PreviewOrderEdit($orderId, $lines, $addressId, $gift));
        $money = fn (Money $amount): MoneyData => $this->money($amount, $order->storeId, $order->exponent);
        $some = fn (Money $amount): ?MoneyData => $amount->isZero() ? null : $money($amount);
        // What the order holds, against what it owed before and owes after (R2-37): a refund once it holds more than its
        // new total; else the change in what is still owed - more, less or none.
        $held = $plan->difference->minorUnits + $plan->totals->orderTotal->minorUnits;
        $owedBefore = max(0, $order->total->minorUnits - $held);
        $owedAfter = max(0, $plan->totals->orderTotal->minorUnits - $held);
        [$words, $amount] = $held > $plan->totals->orderTotal->minorUnits
            ? ['REFUND', $held - $plan->totals->orderTotal->minorUnits]
            : ($owedAfter > $owedBefore ? ['MORE', $owedAfter - $owedBefore] : ($owedAfter < $owedBefore ? ['LESS', $owedBefore - $owedAfter] : ['NONE', 0]));
        $names = $this->catalog->variantBriefs(array_map(static fn (GiftOfferDto $option): string => $option->variantId, $plan->giftOptions));

        return new EditPreviewData(
            $money($plan->totals->grossSubtotal),
            $some($plan->totals->couponDiscount),
            $some($plan->totals->pointsDiscount),
            $money($plan->totals->shipping),
            $money($plan->totals->vat),
            $money($plan->totals->orderTotal),
            $money($order->total),
            $words,
            $money(Money::of($amount, $plan->difference->currencyCode)),
            array_map(fn (GiftOfferDto $option): EditGiftData => new EditGiftData(
                $option->levelId,
                isset($names[$option->variantId]) ? ($this->en() ? ($names[$option->variantId]->productNameEn ?? $names[$option->variantId]->productNameAr) : $names[$option->variantId]->productNameAr) : $option->variantId,
                $money($option->amount),
            ), $plan->giftOptions),
            $plan->giftLevelId,
        );
    }

    private function values(OrderLineView $line): string
    {
        return implode(' · ', array_map(fn (array $value): string => ($this->en() ? $value['attributeEn'] : $value['attributeAr']).': '.($this->en() ? $value['valueEn'] : $value['valueAr']), $line->values));
    }

    private function money(Money $amount, string $storeId, int $exponent): MoneyData
    {
        $store = $this->platform->store(StoreId::fromString($storeId));

        try {
            return $store === null ? throw new InvalidArgumentException('No store.') : MoneyData::of($amount, $store, $this->en() ? 'en' : 'ar');
        } catch (InvalidArgumentException) {
            return new MoneyData($amount->format($exponent).' '.$amount->currencyCode, $amount->minorUnits);
        }
    }

    private function en(): bool
    {
        return $this->app->getLocale() === 'en';
    }
}
