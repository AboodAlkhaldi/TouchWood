<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewProductPrices;

use Modules\Platform\Public\Dto\MoneyData;

/**
 * One of the product's running or scheduled sales in a store (pricing.md §4.3.3, the product page): "−10%,
 * until 30 Oct" - a percentage (basis points) or a sale price, its sizes, its dates (ISO 8601), its state
 * and "always wins".
 */
final readonly class ProductSale
{
    /**
     * @param  list<string>  $variantIds
     */
    public function __construct(
        public string $id,
        public string $state,
        public ?int $percentBasisPoints,
        public ?MoneyData $salePrice,
        public array $variantIds,
        public string $startsAt,
        public ?string $endsAt,
        public bool $alwaysWins,
    ) {}
}
