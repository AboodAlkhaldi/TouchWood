<?php

namespace Modules\Platform\Application\Command\UpdateStore;

/**
 * Only the given fields change. Code, country and currency are accepted only so an attempt
 * to change them is refused explicitly instead of being silently ignored.
 */
final readonly class UpdateStore
{
    public function __construct(
        public string $storeCode,
        public ?string $nameAr = null,
        public ?string $nameEn = null,
        public ?int $taxRateBasisPoints = null,
        public ?string $timezone = null,
        public ?int $position = null,
        public ?string $newCode = null,
        public ?string $countryCode = null,
        public ?string $currencyCode = null,
    ) {}
}
