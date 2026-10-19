<?php

declare(strict_types=1);

namespace Modules\Shipping\Public\Dto;

/**
 * Where a parcel goes, as Shipping needs it (sales.md §2.4) — never Access's address. Provisional:
 * stage 7 settles the fields with its carriers.
 */
final readonly class ShippingDestinationDto
{
    public function __construct(
        public string $countryCode,
        public string $city,
        public ?string $district = null,
        public ?string $postalCode = null,
    ) {}
}
