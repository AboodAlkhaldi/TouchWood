<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\FindLeftOutProducts;

/**
 * **The products a category discount can leave out** (pricing.md §4.3.3 P3 "Left Out", Q10): the
 * category's own products and its children's, found by a name or a code, 20 at a time.
 */
final readonly class FindLeftOutProducts
{
    public const int PER_PAGE = 20;

    public function __construct(
        public string $storeId,
        public string $categoryId,
        public ?string $search = null,
        public ?string $after = null,
    ) {}
}
