<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\CreateCarrier;

/**
 * A new carrier of a store, in one of the countries the store ships to (shipping.md §1.3). It goes
 * last in its country's list (step 3 Q5).
 */
final readonly class CreateCarrier
{
    public function __construct(
        public string $storeId,
        public string $countryCode,
        public string $nameAr,
        public string $nameEn,
        public ?string $logoMediaId = null,
        public ?string $phone = null,
        public ?string $website = null,
        public ?string $trackingPattern = null,
        public bool $active = true,
    ) {}
}
