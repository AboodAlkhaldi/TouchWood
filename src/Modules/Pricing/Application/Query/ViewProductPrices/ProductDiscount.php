<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewProductPrices;

use Modules\Platform\Public\Dto\MoneyData;

/**
 * A category discount reaching the product in a store, running or scheduled (pricing.md §4.3.3, the
 * product page): "Kitchens: 10% off, until 15 Nov", linked - its category with the path to it in the
 * page's language, a percentage (basis points) or an amount off, its lines, dates, state, "always wins".
 */
final readonly class ProductDiscount
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
        public string $startsAt,
        public ?string $endsAt,
        public bool $alwaysWins,
    ) {}
}
