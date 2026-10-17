<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListCategoryDiscounts;

/**
 * A page of category discounts, and how many the store has.
 */
final readonly class DiscountList
{
    /**
     * @param  list<DiscountRow>  $discounts
     */
    public function __construct(
        public array $discounts,
        public int $total,
    ) {}
}
