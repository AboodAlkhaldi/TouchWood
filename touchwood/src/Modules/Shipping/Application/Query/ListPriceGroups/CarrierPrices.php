<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ListPriceGroups;

/**
 * A carrier's price groups, and the version they were read at: a save or a delete sends it back, and is
 * refused with PricesChanged when another save came first (shipping.md §1.4).
 */
final readonly class CarrierPrices
{
    /**
     * @param  list<PriceGroupRow>  $groups
     */
    public function __construct(
        public string $version,
        public array $groups,
    ) {}
}
