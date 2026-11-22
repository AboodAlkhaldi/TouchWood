<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\RemoveCategoryDiscount;

/**
 * Remove a category discount that has not started (pricing.md §3, §4.1).
 */
final readonly class RemoveCategoryDiscount
{
    public function __construct(
        public string $discountId,
    ) {}
}
