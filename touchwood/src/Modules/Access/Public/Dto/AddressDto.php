<?php

declare(strict_types=1);

namespace Modules\Access\Public\Dto;

/**
 * One address of a customer, as other modules see it (Access spec §2.4). Sales reads the customer's
 * addresses in the store being ordered from, default first, and puts a copy of the chosen one on
 * the order; Shipping prints `formatted`, which is that store's own layout.
 *
 * `isComplete` is false when the store's address format has asked for something since this address
 * was saved (amendment 41), or when its country has a place list and its region was typed - a list
 * that arrived later (owner, 2026-10-28, step 2 Q3): it may not be used for an order until the
 * customer completes it.
 *
 * `countryCode` is one of Platform's address countries, whatever the store's own (amendment 76);
 * `regionId`, `cityId` and `districtId` are Platform's places, NULL where typed. `formatted` ends with
 * the country's name, in the language the address was saved in (step 2 Q1).
 */
final readonly class AddressDto
{
    /**
     * @param  array<string, string>  $fields  the store format's values, in its own order
     */
    public function __construct(
        public string $id,
        public string $customerId,
        public string $storeId,
        public string $label,
        public string $recipientName,
        public array $fields,
        public ?float $latitude,
        public ?float $longitude,
        public bool $isDefault,
        public bool $isComplete,
        public string $formatted,
        public string $countryCode,
        public ?string $regionId,
        public ?string $cityId,
        public ?string $districtId,
    ) {}
}
