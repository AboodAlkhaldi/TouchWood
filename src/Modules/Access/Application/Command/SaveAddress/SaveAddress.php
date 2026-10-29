<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SaveAddress;

/**
 * One address of the customer acting, in the store being shopped (amendment 76(a)). With no
 * $addressId it is a new address; with one, that address is changed — its store never changes.
 *
 * $fields carries the store format's values, by key. $isDefault true makes this the store's default;
 * false leaves the flag where it is, because a store that has addresses always has a default.
 *
 * $countryCode is one of Platform's address countries — for a new address the store's own when none
 * is given; an edit given none keeps the address's own country and places. Where
 * Platform has a place list for it, $regionId is picked, and $cityId and $districtId are picked or
 * left NULL for "Other" (typed in $fields). $language is the page's: the picked places' names fill
 * their fields in it (amendment 76(d), (e)).
 */
final readonly class SaveAddress
{
    /**
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public string $storeId,
        public string $label,
        public string $recipientName,
        public string $phone,
        public array $fields,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public bool $isDefault = false,
        public ?string $addressId = null,
        public ?string $countryCode = null,
        public ?string $regionId = null,
        public ?string $cityId = null,
        public ?string $districtId = null,
        public string $language = 'ar',
    ) {}
}
