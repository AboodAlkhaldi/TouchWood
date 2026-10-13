<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\OverlapsFor;

/**
 * What a sale affects - the sizes it reaches - and every other sale on those sizes in the same dates
 * (pricing.md §1.3; owner, 2026-10-08/09), so staff change the dates or keep both knowing the lowest
 * wins. Category discounts join it with their step.
 */
final readonly class OverlapNote
{
    /**
     * @param  list<string>  $variantIds
     * @param  list<OverlappingSale>  $sales
     */
    public function __construct(
        public string $productId,
        public array $variantIds,
        public array $sales,
    ) {}
}
