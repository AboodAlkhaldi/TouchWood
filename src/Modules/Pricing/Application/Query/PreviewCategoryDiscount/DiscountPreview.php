<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\PreviewCategoryDiscount;

/**
 * What a category discount does in a store (pricing.md §1.5): the products it reaches with their sizes'
 * prices, and the products it leaves out.
 */
final readonly class DiscountPreview
{
    /**
     * @param  list<PreviewProduct>  $reached
     * @param  list<PreviewProduct>  $leftOut
     */
    public function __construct(
        public array $reached,
        public array $leftOut,
    ) {}
}
