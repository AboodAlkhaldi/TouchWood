<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\AddStoreCountry;

/**
 * Let a store ship to a country (platform.md, stage 7 (b); shipping.md §1.3). Super Admin only.
 */
final readonly class AddStoreCountry
{
    public function __construct(
        public string $storeCode,
        public string $countryCode,
    ) {}
}
