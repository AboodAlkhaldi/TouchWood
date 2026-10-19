<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\PreviewCategoryDiscount;

use Modules\Pricing\Application\Query\OverlapsFor\OverlappingDiscount;
use Modules\Pricing\Application\Query\OverlapsFor\OverlappingSale;

/**
 * What a category discount does in a store (pricing.md §1.5, §4.3.3 P3): **totals over everything it
 * reaches** - "Reaches 124 sizes in 38 products" (`reachedSizes`, `reachedProducts`), the sizes it skips,
 * "Up to 31% off" (`deepestBasisPoints`, none when it reaches nothing), "12 sizes have no price and are
 * left out" (`unpricedSizes`: the category's sizes switched on in the store with no price) - and **its
 * products, 20 at a time** by name in the page's language, each with its priced sizes (`next` the cursor
 * of the page after); the products it leaves out; and - the same note a sale has (§1.3) - every sale on
 * the sizes it reaches and every other discount on the same products in the same dates.
 */
final readonly class DiscountPreview
{
    /**
     * @param  list<PreviewProduct>  $reached  this page's
     * @param  list<PreviewProduct>  $leftOut
     * @param  list<OverlappingSale>  $overlappingSales
     * @param  list<OverlappingDiscount>  $overlappingDiscounts
     */
    public function __construct(
        public array $reached,
        public ?string $next,
        public int $reachedProducts,
        public int $reachedSizes,
        public int $skippedSizes,
        public int $unpricedSizes,
        public ?int $deepestBasisPoints,
        public array $leftOut,
        public array $overlappingSales = [],
        public array $overlappingDiscounts = [],
    ) {}
}
