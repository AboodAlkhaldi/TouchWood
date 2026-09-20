<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SaveAddress;

/**
 * One address of the customer acting, in the store of the country they picked (spec §1.9). With no
 * $addressId it is a new address; with one, that address is changed — its store never changes, an
 * address in another country being a new address there.
 *
 * $fields carries the store format's values, by key. $isDefault true makes this the store's default;
 * false leaves the flag where it is, because a store that has addresses always has a default.
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
    ) {}
}
