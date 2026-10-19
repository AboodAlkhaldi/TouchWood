<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\CategoryChoices;

/**
 * **The categories a discount can be put on** (pricing.md §4.3.3 P3, the discount dialog's Category): the
 * categories switched on.
 */
final readonly class CategoryChoices
{
    public function __construct(
        public string $storeId,
    ) {}
}
