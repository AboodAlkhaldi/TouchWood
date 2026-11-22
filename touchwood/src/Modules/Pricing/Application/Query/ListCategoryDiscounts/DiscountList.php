<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListCategoryDiscounts;

/**
 * A page of category discounts: its rows, the cursor of the next page (none on the last), and how many
 * the filter finds.
 */
final readonly class DiscountList
{
    /**
     * @param  list<DiscountRow>  $discounts
     */
    public function __construct(
        public array $discounts,
        public ?string $next,
        public int $total,
    ) {}
}
