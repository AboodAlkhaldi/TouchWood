<?php

declare(strict_types=1);

namespace Modules\Shipping\Public\Dto;

/**
 * A carrier as it is now (shipping.md §2.1): Sales copies its names onto an order when it is placed,
 * and lists the store's carriers when an order is marked shipped.
 */
final readonly class CarrierDto
{
    public function __construct(
        public string $id,
        public string $storeId,
        public string $countryCode,
        public string $nameAr,
        public string $nameEn,
        public ?string $logoUrl,
        public ?string $phone,
        public ?string $website,
        public bool $hasTrackingPattern,
        public bool $active,
    ) {}
}
