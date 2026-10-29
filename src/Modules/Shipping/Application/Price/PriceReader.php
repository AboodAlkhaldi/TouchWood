<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Price;

/**
 * What the quote and the uncovered places read of the prices (shipping.md §1.2, §1.6): only
 * switched-on carriers count - a switched-off one is in no quote (§1.3).
 */
interface PriceReader
{
    /**
     * The prices of the store's switched-on carriers in the country on any of these places.
     *
     * @param  list<string>  $placeIds
     * @return list<array{carrierId: string, placeId: string, amountMinor: int}>
     */
    public function onPlaces(string $storeId, string $countryCode, array $placeIds): array;

    /**
     * Every place priced by a switched-on carrier of the store in the country.
     *
     * @return list<string>
     */
    public function pricedPlaces(string $storeId, string $countryCode): array;
}
