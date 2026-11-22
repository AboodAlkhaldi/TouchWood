<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ListPriceGroups;

/**
 * A carrier's price groups (shipping.md §4.1, the carrier's page): each amount with its places.
 */
final readonly class ListPriceGroups
{
    public function __construct(
        public string $storeId,
        public string $carrierId,
        public string $locale,
    ) {}
}
