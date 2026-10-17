<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\PreviewCategoryDiscount;

use Modules\Pricing\Application\Query\OverlapsFor\OverlappingDiscount;
use Modules\Pricing\Application\Query\OverlapsFor\OverlappingSale;

/**
 * What a category discount does in a store (pricing.md §1.5): the products it reaches with their sizes'
 * prices, the products it leaves out, and - the same note a sale has (§1.3) - every sale on the sizes it
 * reaches and every other discount on the same products in the same dates.
 */
final readonly class DiscountPreview
{
    /**
     * @param  list<PreviewProduct>  $reached
     * @param  list<PreviewProduct>  $leftOut
     * @param  list<OverlappingSale>  $overlappingSales
     * @param  list<OverlappingDiscount>  $overlappingDiscounts
     */
    public function __construct(
        public array $reached,
        public array $leftOut,
        public array $overlappingSales = [],
        public array $overlappingDiscounts = [],
    ) {}
}
