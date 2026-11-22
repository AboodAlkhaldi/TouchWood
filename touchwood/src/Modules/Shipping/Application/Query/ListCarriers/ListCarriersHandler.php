<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ListCarriers;

use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Modules\Shipping\Application\Carrier\CarrierChange;
use Modules\Shipping\Application\Query\CarrierRow;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * The Carriers screen (shipping.md §4.1): every country the store ships to - its own first, then by
 * name in the page's language as Platform sorts them - each with its carriers in their order, off
 * ones too. A country with no carrier is listed, empty: every address there is NOT_COVERED (§1.3).
 */
final readonly class ListCarriersHandler
{
    public const string PERMISSION = ShippingPermissions::CARRIERS_VIEW;

    public function __construct(
        private CarrierChange $change,
        private CarrierRepository $carriers,
        private PlatformApi $platform,
    ) {}

    /**
     * @return list<CarrierCountry>
     *
     * @throws InvalidShippingInput|Unauthorized
     */
    public function handle(ListCarriers $query): array
    {
        $store = $this->change->authorize(self::PERMISSION, $query->storeId);
        $shipsTo = $this->platform->storeCountries(StoreId::fromString($store->id));
        $byCountry = [];

        foreach ($this->carriers->inStore($store->id) as $carrier) {
            $byCountry[$carrier->countryCode][] = CarrierRow::of($carrier);
        }

        $countries = [];

        // Platform's address countries, in the page's language's order; a ship-to country is always
        // one in use (a shipped country cannot be retired - platform.md §4.3 Q8).
        foreach ($this->platform->addressCountries($query->locale) as $country) {
            if (in_array($country->code, $shipsTo, true)) {
                $countries[] = new CarrierCountry($country->code, $country->name, $country->code === $store->countryCode, $byCountry[$country->code] ?? []);
            }
        }

        // A carrier is never in a country the store stopped shipping to (CarriersHoldCountry); were
        // one to be, it is still shown rather than lost.
        foreach (array_diff(array_keys($byCountry), array_map(static fn (CarrierCountry $country): string => $country->code, $countries)) as $code) {
            $countries[] = new CarrierCountry($code, new TranslatedTextDto($code, $code), false, $byCountry[$code]);
        }

        usort($countries, static fn (CarrierCountry $a, CarrierCountry $b): int => $b->own <=> $a->own);

        return $countries;
    }
}
