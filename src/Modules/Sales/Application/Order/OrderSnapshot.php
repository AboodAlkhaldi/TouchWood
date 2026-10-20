<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Modules\Access\Public\Dto\AddressDto;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Public\Dto\LinePriceDto;
use Modules\Sales\Application\Quote\QuoteView;
use Modules\Sales\Domain\Exception\QuoteChanged;
use Modules\Sales\Domain\Model\NewOrder;
use Modules\Sales\Domain\Model\NewOrderLine;
use Modules\Sales\Domain\Service\OrderShares;
use Shared\Domain\ValueObject\Money;

/**
 * An order as its quote placed it (sales.md §1.3 step 7): **everything snapshotted** (handoff §12.3) —
 * the customer's name, email and phone, the delivery address, each line's product and size names and
 * values in both languages (the English name falling back to the Arabic, answer 44) and its code, its
 * prices and kind, its shares of the coupon, the points and the VAT (§1.8); the gift; every total; the
 * rules it was placed under.
 */
final readonly class OrderSnapshot
{
    public function __construct(
        private CatalogApi $catalog,
    ) {}

    /** @throws QuoteChanged when a size of the quote no longer exists */
    public function of(QuoteView $quote, string $orderId, string $number, CustomerDto $customer, AddressDto $address, DateTimeImmutable $placedAt): NewOrder
    {
        $prices = $quote->prices;
        $totals = $quote->totals;
        $currency = $prices->currencyCode;
        $variantIds = array_values(array_unique([...array_map(static fn (LinePriceDto $line): string => $line->variantId, $prices->lines), ...($quote->giftVariantId === null ? [] : [$quote->giftVariantId])]));
        $variants = $this->catalog->variants($variantIds);
        $products = $this->catalog->products(array_values(array_unique(array_map(static fn ($variant): string => $variant->productId, $variants))));

        $shares = OrderShares::of(
            array_map(static fn (LinePriceDto $line): Money => $line->total, $prices->lines),
            $quote->couponShares === [] ? array_map(static fn (): Money => Money::zero($currency), $prices->lines) : array_map(static fn (int $share): Money => Money::of($share, $currency), $quote->couponShares),
            $totals->pointsDiscount,
            $totals->vat,
            $totals->shipping,
        );

        $line = function (string $variantId) use ($variants, $products): array {
            $variant = $variants[$variantId] ?? throw new QuoteChanged('lines');
            $product = $products[$variant->productId] ?? throw new QuoteChanged('lines');

            return [$variant, $product->nameAr, $product->nameEn ?? $product->nameAr, array_map(static fn ($value): array => [
                'attributeAr' => $value->attribute->ar, 'attributeEn' => $value->attribute->en, 'valueAr' => $value->value->ar, 'valueEn' => $value->value->en,
            ], $variant->values)];
        };

        $lines = [];

        foreach ($prices->lines as $index => $priced) {
            [$variant, $nameAr, $nameEn, $values] = $line($priced->variantId);
            $lines[] = new NewOrderLine(
                strtolower((string) Str::ulid()), $variant->productId, $variant->id, false, $nameAr, $nameEn, $values, $variant->code,
                $priced->mode->value, $priced->kind->value, $priced->quantity, $priced->unit->minorUnits, $priced->listUnit->minorUnits, $priced->total->minorUnits,
                $shares['lines'][$index]['coupon']->minorUnits, $shares['lines'][$index]['points']->minorUnits, $shares['lines'][$index]['vat']->minorUnits,
            );
        }

        if ($quote->giftVariantId !== null) {
            [$variant, $nameAr, $nameEn, $values] = $line($quote->giftVariantId);
            $lines[] = new NewOrderLine(
                strtolower((string) Str::ulid()), $variant->productId, $variant->id, true, $nameAr, $nameEn, $values, $variant->code,
                SaleMode::Retail->value, null, 1, 0, 0, 0, 0, 0, 0,
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
                'label' => $address->label, 'recipientName' => $address->recipientName, 'phone' => $address->phone, 'fields' => $address->fields,
                'latitude' => $address->latitude, 'longitude' => $address->longitude, 'formatted' => $address->formatted,
            ],
            $quote->wayToPay->value,
            $quote->couponId,
            $quote->couponId === null ? null : $quote->couponCode,
            $totals->couponDiscount->minorUnits,
            $quote->pointsUsed,
            $totals->pointsDiscount->minorUnits,
            $totals->grossSubtotal->minorUnits,
            $totals->netSubtotal->minorUnits,
            $totals->goodsTotal->minorUnits,
            $totals->shipping->minorUnits,
            $quote->shippingRuleFee->minorUnits,
            $quote->shippingRuleFreeAbove->minorUnits,
            $totals->taxableBase->minorUnits,
            $totals->vat->minorUnits,
            $totals->taxRateBasisPoints,
            $totals->orderTotal->minorUnits,
            $quote->giftLevelId,
            $placedAt,
            $lines,
        );
    }
}
