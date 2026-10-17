<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListCategoryDiscounts;

/**
 * One category discount: its category, a percentage (basis points) or an amount (minor units), its lines,
 * its dates as ISO 8601, "always wins", its state now (`SCHEDULED`, `RUNNING`, `ENDED`) and the products
 * it leaves out. The category is named by the screen from Catalog's category list.
 */
final readonly class DiscountRow
{
    /**
     * @param  list<string>  $leftOut
     */
    public function __construct(
        public string $id,
        public string $categoryId,
        public ?int $percentBasisPoints,
        public ?int $amountMinor,
        public ?string $currencyCode,
        public string $lines,
        public string $startsAt,
        public ?string $endsAt,
        public bool $alwaysWins,
        public string $state,
        public array $leftOut,
    ) {}
}
