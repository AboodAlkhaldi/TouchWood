<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewCategoryDiscount;

/**
 * A category discount's page (pricing.md §3, §4.3.3 P3): the discount and its preview, its products 20 at
 * a time after the cursor of the page before.
 */
final readonly class ViewCategoryDiscount
{
    public function __construct(
        public string $discountId,
        public ?string $after = null,
    ) {}
}
