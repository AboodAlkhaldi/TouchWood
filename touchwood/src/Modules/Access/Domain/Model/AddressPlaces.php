<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use Modules\Access\Domain\Exception\InvalidAddress;

/**
 * Where an address is (amendment 76): its country, from Platform's address countries, and the
 * region, city and district picked from Platform's place list by id - each NULL where it was typed
 * ("Other", or a country with no list). A district is picked only in a picked city, a city only in a
 * picked region. `language` is the page's language when it was saved: the names the picked places
 * filled in, and the country line printed with the address, are in it (76(e)).
 */
final readonly class AddressPlaces
{
    private function __construct(
        public string $countryCode,
        public ?string $regionId,
        public ?string $cityId,
        public ?string $districtId,
        public string $language,
    ) {}

    /**
     * @throws InvalidAddress
     */
    public static function of(string $countryCode, ?string $regionId, ?string $cityId, ?string $districtId, string $language): self
    {
        $countryCode = strtoupper(trim($countryCode));

        if (preg_match('/\A[A-Z]{2}\z/', $countryCode) !== 1) {
            throw new InvalidAddress('country', 'a two-letter country code');
        }

        $regionId = self::id($regionId);
        $cityId = self::id($cityId);
        $districtId = self::id($districtId);

        if (($cityId !== null && $regionId === null) || ($districtId !== null && $cityId === null)) {
            throw new InvalidAddress('places', 'a city is picked in a region, a district in a city');
        }

        return new self($countryCode, $regionId, $cityId, $districtId, $language === 'en' ? 'en' : 'ar');
    }

    /**
     * A row as stored, read back as it is: a row nothing here could have written cannot make a read throw.
     */
    public static function reconstitute(string $countryCode, ?string $regionId, ?string $cityId, ?string $districtId, string $language): self
    {
        return new self($countryCode, $regionId, $cityId, $districtId, $language);
    }

    public function samePlacesAs(self $other): bool
    {
        return $this->regionId === $other->regionId && $this->cityId === $other->cityId && $this->districtId === $other->districtId;
    }

    /**
     * @return list<string> the ids picked, from the region down
     */
    public function ids(): array
    {
        return array_values(array_filter([$this->regionId, $this->cityId, $this->districtId], static fn (?string $id): bool => $id !== null));
    }

    /**
     * @throws InvalidAddress
     */
    private static function id(?string $id): ?string
    {
        $id = $id === null ? '' : strtolower(trim($id));

        if ($id === '') {
            return null;
        }

        if (preg_match('/\A[0-7][0-9a-hjkmnp-tv-z]{25}\z/', $id) !== 1) {
            throw new InvalidAddress('places', 'a place picked from the list');
        }

        return $id;
    }
}
