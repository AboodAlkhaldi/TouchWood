<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\OverlapsFor;

/**
 * What a sale affects - the sizes it reaches - and every other sale on those sizes in the same dates
 * (pricing.md §1.3; owner, 2026-10-08/09), so staff change the dates or keep both knowing the lowest
 * wins - and every category discount reaching the product then (step 7b).
 */
final readonly class OverlapNote
{
    /**
     * @param  list<string>  $variantIds
     * @param  list<OverlappingSale>  $sales
     * @param  list<OverlappingDiscount>  $discounts
     */
    public function __construct(
        public string $productId,
        public array $variantIds,
        public array $sales,
        public array $discounts = [],
    ) {}
}
