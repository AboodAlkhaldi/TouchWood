<?php

declare(strict_types=1);

namespace Modules\Shipping\Public\Dto;

/**
 * Where a parcel goes, as Shipping needs it (shipping.md §2.1) - never Access's address. The place's
 * ids from Platform's list when the country has one; the typed text otherwise, or where the customer
 * typed a city or a district of their own ("Other").
 */
final readonly class ShippingDestinationDto
{
    public function __construct(
        public string $countryCode,
        public ?string $regionId = null,
        public ?string $cityId = null,
        public ?string $districtId = null,
        public ?string $cityText = null,
        public ?string $districtText = null,
    ) {}
}
