<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewProductPrices;

/**
 * A campaign run pricing some of the product's sizes in a store, running or scheduled (pricing.md §9.6 #7,
 * the product page): read from the sizes' candidates, so it costs no query - its run's id (its name is
 * Content's), its lines, the sizes it prices, its dates (ISO 8601) and state.
 */
final readonly class ProductCampaign
{
    /**
     * @param  list<string>  $variantIds
     */
    public function __construct(
        public string $id,
        public string $state,
        public string $lines,
        public array $variantIds,
        public string $startsAt,
        public ?string $endsAt,
    ) {}
}
