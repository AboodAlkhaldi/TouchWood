<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ListUncoveredPlaces;

use Collator;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Shipping\Application\Carrier\CarrierChange;
use Modules\Shipping\Application\Query\PlaceRow;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Application\Uncovered\UncoveredPlaces;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Read live, so a place Platform added a moment ago is listed at once (§8 #9); the menu's count follows
 * within ten minutes. Countries in the Carriers screen's order: the store's own first, then by name.
 */
final readonly class ListUncoveredPlacesHandler
{
    public const string PERMISSION = ShippingPermissions::CARRIERS_VIEW;

    public function __construct(
        private CarrierChange $change,
        private PlatformApi $platform,
        private UncoveredPlaces $uncovered,
    ) {}

    /**
     * @return list<UncoveredCountry>
     *
     * @throws InvalidShippingInput|Unauthorized
     */
    public function handle(ListUncoveredPlaces $query): array
    {
        $store = $this->change->authorize(self::PERMISSION, $query->storeId);
        $shipsTo = $this->platform->storeCountries(StoreId::fromString($store->id));
        $collator = new Collator($query->locale === 'ar' ? 'ar' : 'en');
        $countries = [];

        foreach ($this->platform->addressCountries($query->locale) as $country) {
            if (! in_array($country->code, $shipsTo, true)) {
                continue;
            }

            $places = $this->uncovered->in($store->id, $country->code);
            $parents = $this->platform->place(PlaceRow::parentIds($places));
            $rows = array_map(static fn ($place): PlaceRow => PlaceRow::of($place, $parents), $places);
            usort($rows, static fn (PlaceRow $a, PlaceRow $b): int => (int) $collator->compare($a->name->in($query->locale), $b->name->in($query->locale)));
            $countries[] = new UncoveredCountry($country->code, $country->name, $rows);
        }

        usort($countries, static fn (UncoveredCountry $a, UncoveredCountry $b): int => ($b->code === $store->countryCode) <=> ($a->code === $store->countryCode));

        return $countries;
    }
}
