<?php

declare(strict_types=1);

namespace Modules\Access\Application\Address;

use Locale;
use Modules\Access\Domain\Model\Address;
use Modules\Access\Domain\Repository\StoreAddressFormatRepository;
use Modules\Access\Public\Dto\AddressDto;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AddressCountryDto;

/**
 * An address as other modules see it (spec §2.4): its store's layout applied, with the country's
 * name as its last line (owner, 2026-10-28, step 2 Q1), and whether it can be used for an order.
 * It cannot while it no longer satisfies its store's format - a format that asked for a new field
 * leaves older addresses incomplete (amendment 41) - nor while its country has a place list and its
 * region was typed: a list that arrived after it was saved (step 2 Q3), since no carrier can price
 * a place it cannot name.
 *
 * The format is read for every address: it comes from the cache, and keeping a copy here would
 * outlive a change made in the same request (review of step 5). The countries with a list are read
 * once per call.
 */
final readonly class AddressMapper
{
    public function __construct(
        private StoreAddressFormatRepository $formats,
        private PlatformApi $platform,
    ) {}

    public function toDto(Address $address): AddressDto
    {
        return $this->map($address, $this->countriesWithPlaces());
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<AddressDto>
     */
    public function toDtos(array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }

        $listed = $this->countriesWithPlaces();

        return array_map(fn (Address $address): AddressDto => $this->map($address, $listed), $addresses);
    }

    /**
     * @param  list<string>  $listed  the countries Platform has a place list for
     */
    private function map(Address $address, array $listed): AddressDto
    {
        $format = $this->formats->forStore($address->storeId());
        // In the store's own order: jsonb keeps no key order, so the row's is whatever it liked.
        $fields = $format === null ? $address->fields() : $format->order($address->fields());
        $places = $address->places();
        $typedWhereListed = in_array($places->countryCode, $listed, true) && $places->regionId === null;
        $formatted = $format?->render($fields) ?? '';
        $country = Locale::getDisplayRegion('-'.$places->countryCode, $places->language);

        return new AddressDto(
            $address->id(),
            $address->customerId(),
            $address->storeId(),
            $address->label(),
            $address->recipientName(),
            $address->phone()->value,
            $fields,
            $address->pin()?->latitude,
            $address->pin()?->longitude,
            $address->isDefault(),
            ($format?->satisfiedBy($fields) ?? false) && ! $typedWhereListed,
            $formatted === '' ? '' : $formatted."\n".($country === false || $country === '' ? $places->countryCode : $country),
            $places->countryCode,
            $places->regionId,
            $places->cityId,
            $places->districtId,
        );
    }

    /**
     * @return list<string>
     */
    private function countriesWithPlaces(): array
    {
        return array_values(array_map(
            static fn (AddressCountryDto $country): string => $country->code,
            array_filter($this->platform->addressCountries('en'), static fn (AddressCountryDto $country): bool => $country->hasPlaces),
        ));
    }
}
