<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewCategoryDiscount;

use Modules\Pricing\Application\Query\ListCategoryDiscounts\DiscountRow;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\DiscountPreview;

/**
 * A category discount as its page shows it: the discount, and what it does now (§1.5's preview).
 */
final readonly class CategoryDiscountView
{
    public function __construct(
        public DiscountRow $discount,
        public DiscountPreview $preview,
    ) {}
}
