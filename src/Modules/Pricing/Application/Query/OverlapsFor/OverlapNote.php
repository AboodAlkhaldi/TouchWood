<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\OverlapsFor;

/**
 * What a sale affects - the sizes it reaches - and every other sale on those sizes in the same dates
 * (pricing.md §1.3; owner, 2026-10-08/09), so staff change the dates or keep both knowing the lowest
 * wins - and every category discount reaching the product then (step 7b). With the sale's price or
 * percentage typed (stage 5's screens step 2): each size's new price, whether it wins (`atStart`: judged
 * at the sale's start, it being scheduled), and its depth against the store's ceiling (`ceilingPercent`).
 */
final readonly class OverlapNote
{
    /**
     * @param  list<string>  $variantIds
     * @param  list<OverlappingSale>  $sales
     * @param  list<OverlappingDiscount>  $discounts
     * @param  list<OverlapSize>  $sizes  none until a price or a percentage is typed
     */
    public function __construct(
        public string $productId,
        public array $variantIds,
        public array $sales,
        public array $discounts = [],
        public array $sizes = [],
        public bool $atStart = false,
        public ?int $ceilingPercent = null,
    ) {}
}
