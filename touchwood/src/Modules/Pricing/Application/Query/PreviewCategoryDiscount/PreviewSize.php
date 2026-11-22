<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\PreviewCategoryDiscount;

use Modules\Platform\Public\Dto\MoneyData;

/**
 * One priced size a discount reaches (pricing.md §1.5, §4.3.3 P3): its code and name in the page's
 * language; its retail price; its new price and the difference - or, when the discount skips it, why
 * (Q9): `zero_or_below` ("Would be 0 or below.") or `past_ceiling` ("Past the ceiling in this wired
 * store.").
 */
final readonly class PreviewSize
{
    public function __construct(
        public string $variantId,
        public string $code,
        public string $name,
        public MoneyData $retail,
        public ?MoneyData $newPrice,
        public ?MoneyData $difference,
        public ?string $skipped,
    ) {}
}
