<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewCategoryDiscount;

use Modules\Pricing\Application\Query\ListCategoryDiscounts\DiscountRow;

/**
 * A category discount's store and its fields, as its list row shows them.
 */
final readonly class DiscountFields
{
    public function __construct(
        public string $storeId,
        public DiscountRow $discount,
    ) {}
}
