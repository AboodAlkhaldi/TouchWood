<?php

declare(strict_types=1);

namespace Modules\Shipping\Public\Dto;

use Modules\Shipping\Public\Enums\ShippingCoverage;

/**
 * Where an address stands for a store, and the carriers that serve it (shipping.md §1.2, §2.1): the
 * options only when SERVED, by fee and then by the carrier's place in the list - the first is the
 * cheapest, picked by default (§9.3 #2); the other store only when OTHER_STORE.
 */
final readonly class ShippingQuoteDto
{
    /**
     * @param  list<ShippingOptionDto>  $options
     */
    public function __construct(
        public ShippingCoverage $coverage,
        public array $options = [],
        public ?string $otherStoreId = null,
    ) {}
}
