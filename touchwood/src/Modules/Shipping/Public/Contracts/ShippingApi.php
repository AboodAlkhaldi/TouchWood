<?php

declare(strict_types=1);

namespace Modules\Shipping\Public\Contracts;

use Modules\Shipping\Public\Dto\BoxSuggestionDto;
use Modules\Shipping\Public\Dto\CarrierDto;
use Modules\Shipping\Public\Dto\ParcelLineDto;
use Modules\Shipping\Public\Dto\ShippingDestinationDto;
use Modules\Shipping\Public\Dto\ShippingQuoteDto;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * What Sales asks Shipping (shipping.md §2.1; replaces the provisional `options` Sales declared in
 * sales.md §2.4). Shipping's own types only (handoff §4.4: Shipping → Platform, Catalog).
 */
interface ShippingApi
{
    /**
     * Where the address stands for this store, and the carriers that serve it with their fees for an
     * order of this goods total - after the coupon and the points, before shipping and VAT (§1.5).
     */
    public function quote(StoreId $store, ShippingDestinationDto $to, Money $goodsTotal): ShippingQuoteDto;

    /** One carrier as it is now, any store's - null once it is deleted. */
    public function carrier(string $carrierId): ?CarrierDto;

    /**
     * The store's carriers, switched-on ones first, each country's in their order (§9.1 S17).
     *
     * @return list<CarrierDto>
     */
    public function carriers(StoreId $store): array;

    /** The carrier's tracking link for this number, or null when it has no pattern or is gone (§1.3). */
    public function trackingLink(string $carrierId, string $trackingNumber): ?string;

    /**
     * The box suggested for an order's sizes, or "pack by hand" with why - null when the store has no
     * switched-on box (§1.7). Worked out when it is shown, never stored; it changes no price.
     *
     * @param  list<ParcelLineDto>  $lines
     */
    public function suggestBox(StoreId $store, array $lines): ?BoxSuggestionDto;
}
