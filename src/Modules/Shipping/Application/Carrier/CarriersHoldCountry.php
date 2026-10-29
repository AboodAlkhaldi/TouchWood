<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Carrier;

use Modules\Platform\Public\Contracts\ShipToCountryGuard;
use Modules\Shipping\Domain\Exception\CountryHasCarriers;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Shared\Domain\ValueObject\StoreId;

/**
 * Shipping's say over taking a country off a store's ship-to list (shipping.md §1.3, §9.3 #9): refused
 * while the store has a carrier there, switched off or not - they are deleted first. Platform asks
 * inside its change's transaction; the store's carriers' lock taken here makes a carrier being
 * created there meanwhile either finish first (and refuse this) or wait and find the country gone
 * (owner, 2026-10-29, step 3 Q3: filled with the carriers, not in step 4).
 */
final readonly class CarriersHoldCountry implements ShipToCountryGuard
{
    public function __construct(
        private CarrierRepository $carriers,
    ) {}

    public function check(StoreId $store, string $countryCode): void
    {
        $this->carriers->lockStore($store->value);
        $count = $this->carriers->countIn($store->value, strtoupper($countryCode));

        if ($count > 0) {
            throw new CountryHasCarriers(strtoupper($countryCode), $count);
        }
    }
}
