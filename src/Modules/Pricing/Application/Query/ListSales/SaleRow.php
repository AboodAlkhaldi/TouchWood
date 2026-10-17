<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListSales;

/**
 * One sale on the list: its product's names, a percentage (basis points) or a price (minor units), its
 * dates as ISO 8601, "always wins", its state now (`SCHEDULED`, `RUNNING`, `ENDED`) and its sizes.
 */
final readonly class SaleRow
{
    /**
     * @param  list<string>  $variantIds
     */
    public function __construct(
        public string $id,
        public string $productId,
        public string $productNameAr,
        public ?string $productNameEn,
        public ?int $percentBasisPoints,
        public ?int $priceMinor,
        public ?string $currencyCode,
        public string $startsAt,
        public ?string $endsAt,
        public bool $alwaysWins,
        public string $state,
        public array $variantIds,
    ) {}
}
