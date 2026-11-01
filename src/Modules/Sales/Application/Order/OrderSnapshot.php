<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Modules\Access\Public\Dto\AddressDto;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Modules\Pricing\Public\Dto\LinePriceDto;
use Modules\Sales\Application\Quote\QuoteView;
use Modules\Sales\Application\Quote\ShippingQuotes;
use Modules\Sales\Domain\Exception\QuoteChanged;
use Modules\Sales\Domain\Model\NewOrder;
use Modules\Sales\Domain\Model\NewOrderLine;
use Modules\Sales\Domain\Model\OrderShipping;
use Modules\Sales\Domain\Service\OrderShares;
use Shared\Domain\Text\LatinDigits;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * An order as its quote placed it (sales.md §1.3 step 7): **everything snapshotted** (handoff §12.3) —
 * the customer's name, email and phone (the one phone delivery uses: an address has none, access.md
 * amendment 89), the delivery address and its place, each line's product and size names and values in both
 * languages (the English name falling back to the Arabic, answer 44) and its code, its
 * prices and kind, its shares of the coupon, the points and the VAT (§1.8); the gift; every total; the
 * rules it was placed under; the carrier and its names, or the shipping fee to be set (§9.5 #2, #11).
 */
final readonly class OrderSnapshot
{
    public function __construct(
        private SizeRecords $sizes,
        private InventoryApi $inventory,
        private StoreWiring $wiring,
    ) {}

    /** @throws QuoteChanged when a size of the quote no longer exists */
    public function of(QuoteView $quote, string $orderId, string $number, CustomerDto $customer, AddressDto $address, DateTimeImmutable $placedAt): NewOrder
    {
        $prices = $quote->prices;
        $totals = $quote->totals;
        $shipping = $quote->shipping;
        $currency = $prices->currencyCode;
        $variantIds = array_values(array_unique([...array_map(static fn (LinePriceDto $line): string => $line->variantId, $prices->lines), ...($quote->giftVariantId === null ? [] : [$quote->giftVariantId])]));
        $sizes = $this->sizes->of($variantIds);
        // As Inventory's hold fixes them when it is placed, a moment later in the same transaction (§1.5).
        $store = StoreId::fromString($quote->storeId);
        $wired = $this->wiring->isWired($store);
        $stock = $this->inventory->stock($store, $variantIds);

        $shares = OrderShares::of(
            array_map(static fn (LinePriceDto $line): Money => $line->total, $prices->lines),
            $quote->couponShares === [] ? array_map(static fn (): Money => Money::zero($currency), $prices->lines) : array_map(static fn (int $share): Money => Money::of($share, $currency), $quote->couponShares),
            $totals->pointsDiscount,
            $totals->vat,
        );

        $lines = [];

        foreach ($prices->lines as $index => $priced) {
            $size = $sizes[$priced->variantId] ?? throw new QuoteChanged('lines');
            $lines[] = new NewOrderLine(
                strtolower((string) Str::ulid()), $size['productId'], $priced->variantId, false, $size['nameAr'], $size['nameEn'], $size['values'], $size['code'],
                $priced->mode->value, $priced->kind->value, $priced->quantity, $priced->unit->minorUnits, $priced->listUnit->minorUnits, $priced->total->minorUnits,
                $shares[$index]['coupon']->minorUnits, $shares[$index]['points']->minorUnits, $shares[$index]['vat']->minorUnits,
                $stock[$priced->variantId]->countsOnStock ?? ! $wired,
            );
        }

        if ($quote->giftVariantId !== null) {
            $size = $sizes[$quote->giftVariantId] ?? throw new QuoteChanged('lines');
            $lines[] = new NewOrderLine(
                strtolower((string) Str::ulid()), $size['productId'], $quote->giftVariantId, true, $size['nameAr'], $size['nameEn'], $size['values'], $size['code'],
                SaleMode::Retail->value, null, 1, 0, 0, 0, 0, 0, 0, true,
            );
        }

        return new NewOrder(
            $orderId,
            $quote->storeId,
            $number,
            $quote->id,
            $quote->customerId,
            $quote->audience->value,
            $customer->locale,
            $currency,
            trim($customer->firstName.' '.$customer->lastName),
            $customer->email,
            $customer->phone ?? throw new QuoteChanged('phone'),
            [
                'label' => $address->label, 'recipientName' => $address->recipientName, 'fields' => $address->fields,
                'latitude' => $address->latitude, 'longitude' => $address->longitude, 'formatted' => $address->formatted,
                ...ShippingQuotes::destination($address),
            ],
            $quote->wayToPay->value,
            $quote->couponId,
            $quote->couponId === null || $quote->couponCode === null ? null : strtoupper(LatinDigits::of(trim($quote->couponCode))),
            $totals->couponDiscount->minorUnits,
            $quote->pointsUsed,
            $totals->pointsDiscount->minorUnits,
            $totals->grossSubtotal->minorUnits,
            $totals->netSubtotal->minorUnits,
            $totals->goodsTotal->minorUnits,
            new OrderShipping(
                $totals->shipping->minorUnits, $shipping->price->minorUnits, $shipping->freeAbove?->minorUnits, $shipping->freeKind,
                $shipping->pending(), $shipping->carrierId, $shipping->carrierAr, $shipping->carrierEn,
            ),
            $totals->taxableBase->minorUnits,
            $totals->vat->minorUnits,
            $totals->taxRateBasisPoints,
            $totals->orderTotal->minorUnits,
            $quote->giftLevelId,
            $wired,
            $placedAt,
            $lines,
        );
    }
}
