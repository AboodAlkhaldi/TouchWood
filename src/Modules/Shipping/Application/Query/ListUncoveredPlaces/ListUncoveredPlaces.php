<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ListUncoveredPlaces;

/**
 * "Places no carrier serves", per country the store ships to (shipping.md §1.6, §4.1).
 */
final readonly class ListUncoveredPlaces
{
    public function __construct(
        public string $storeId,
        public string $locale,
    ) {}
}
