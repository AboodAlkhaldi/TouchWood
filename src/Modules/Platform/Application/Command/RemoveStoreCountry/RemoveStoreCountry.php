<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RemoveStoreCountry;

/**
 * Stop a store shipping to a country (shipping.md §1.3, §9.3 #9). Super Admin only.
 */
final readonly class RemoveStoreCountry
{
    public function __construct(
        public string $storeCode,
        public string $countryCode,
    ) {}
}
