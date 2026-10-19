<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewSizePrices;

use Modules\Platform\Public\Dto\MoneyData;

/**
 * A category discount reaching the size's product, running or scheduled (pricing.md §4.3.3 "What Reaches
 * It"): its category, named with the path to it in the page's language ("Kitchens › Cabinets" as a list);
 * a percentage (basis points) or an amount off; its lines (`RETAIL`, `WHOLESALE`, `BOTH`); what it makes
 * this size cost - or why it skips it (`zero_or_below`, `past_ceiling`, Q9); its dates, state and "always
 * wins"; and whether it is what one piece costs now.
 */
final readonly class ReachingDiscount
{
    /**
     * @param  list<string>  $categoryPath
     */
    public function __construct(
        public string $id,
        public string $categoryId,
        public array $categoryPath,
        public string $state,
        public ?int $percentBasisPoints,
        public ?MoneyData $amountOff,
        public string $lines,
        public ?MoneyData $priceOnSize,
        public ?string $skipped,
        public string $startsAt,
        public ?string $endsAt,
        public bool $alwaysWins,
        public bool $winsNow,
    ) {}
}
