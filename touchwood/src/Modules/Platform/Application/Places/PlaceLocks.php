<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Places;

use Modules\Platform\Domain\Exception\AddressCountryNotFound;
use Modules\Platform\Domain\Exception\PlaceNotFound;
use Modules\Platform\Domain\Model\AddressCountry;
use Modules\Platform\Domain\Model\Place;
use Modules\Platform\Domain\Repository\AddressCountryRepository;
use Modules\Platform\Domain\Repository\PlaceRepository;

/**
 * Loads what a change of the places works on, in the one lock order: the country's row first,
 * then the place and what sits above it. Called inside the change's transaction.
 */
final readonly class PlaceLocks
{
    public function __construct(
        private AddressCountryRepository $countries,
        private PlaceRepository $places,
    ) {}

    public static function code(string $countryCode): string
    {
        return strtoupper(trim($countryCode));
    }

    public function country(string $code): AddressCountry
    {
        return $this->countries->byCode($code) ?? throw new AddressCountryNotFound($code);
    }

    /**
     * @return array{AddressCountry, Place}
     */
    public function place(string $id): array
    {
        $code = $this->places->countryOf($id) ?? throw new PlaceNotFound($id);
        $country = $this->country($code);

        return [$country, $this->places->byId($id) ?? throw new PlaceNotFound($id)];
    }

    /**
     * Whether the place is out of every picker: it, its city or its region retired (stage 7
     * step 1, Q4). The country is the caller's to check.
     */
    public function isHidden(Place $place): bool
    {
        while (! $place->isRetired()) {
            if ($place->parentId === null) {
                return false;
            }

            $place = $this->places->byId($place->parentId) ?? throw new PlaceNotFound($place->parentId);
        }

        return true;
    }
}
