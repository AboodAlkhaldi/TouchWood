<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListCategoryDiscounts;

use Modules\Platform\Public\Dto\MoneyData;

/**
 * One category discount (pricing.md §4.3.3 P3): its category - named, and the path to it from the top in
 * the page's language ("Kitchens › Cabinets" as a list); a percentage (basis points) or an amount off; its
 * lines (`RETAIL`, `WHOLESALE`, `BOTH`); its dates as ISO 8601; "always wins"; its state now (`SCHEDULED`,
 * `RUNNING`, `ENDED`); and the products it leaves out, by id and by name in the page's language.
 */
final readonly class DiscountRow
{
    /**
     * @param  list<string>  $categoryPath
     * @param  list<string>  $leftOut
     * @param  list<string>  $leftOutNames  in the order of `leftOut`
     */
    public function __construct(
        public string $id,
        public string $categoryId,
        public array $categoryPath,
        public ?int $percentBasisPoints,
        public ?MoneyData $amountOff,
        public string $lines,
        public string $startsAt,
        public ?string $endsAt,
        public bool $alwaysWins,
        public string $state,
        public array $leftOut,
        public array $leftOutNames,
    ) {}
}
