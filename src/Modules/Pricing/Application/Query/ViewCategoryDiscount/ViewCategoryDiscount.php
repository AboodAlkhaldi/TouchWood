<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewCategoryDiscount;

/**
 * A category discount's page (pricing.md §3): the discount and its preview.
 */
final readonly class ViewCategoryDiscount
{
    public function __construct(
        public string $discountId,
    ) {}
}
