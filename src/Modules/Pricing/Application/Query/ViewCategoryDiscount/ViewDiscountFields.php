<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewCategoryDiscount;

/**
 * **A category discount's fields alone**, without what it reaches (stage 5's screens step 3's review): what
 * Change End sends back with a running discount's new end - never its preview.
 */
final readonly class ViewDiscountFields
{
    public function __construct(
        public string $discountId,
    ) {}
}
