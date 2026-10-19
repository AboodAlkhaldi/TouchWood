<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\FindWholesaleSizes;

/**
 * **The sizes Add Wholesale Prices offers** (pricing.md §4.3.3 P4): the sizes the store sells wholesale,
 * none archived, with no bands yet, found by a name or a code, 20 at a time.
 */
final readonly class FindWholesaleSizes
{
    public const int PER_PAGE = 20;

    public function __construct(
        public string $storeId,
        public ?string $search = null,
        public ?string $after = null,
    ) {}
}
