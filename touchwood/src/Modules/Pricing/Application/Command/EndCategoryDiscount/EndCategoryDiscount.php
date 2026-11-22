<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\EndCategoryDiscount;

/**
 * End a running category discount now (pricing.md §3, §4.1).
 */
final readonly class EndCategoryDiscount
{
    public function __construct(
        public string $discountId,
    ) {}
}
