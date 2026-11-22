<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\NeedsAPrice;

/**
 * The Needs a Price list of one store (pricing.md §1.9, §3, §4.3.3 P5): found by a name in either language
 * or a code, 50 at a time after the cursor of the page before (owner Q14).
 */
final readonly class NeedsAPrice
{
    public const int PER_PAGE = 50;

    public function __construct(
        public string $storeId,
        public ?string $search = null,
        public ?string $after = null,
    ) {}
}
