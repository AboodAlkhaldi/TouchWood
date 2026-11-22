<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListSales;

use Modules\Platform\Public\Dto\MoneyData;

/**
 * One sale on the Offers screen (pricing.md §4.3.3 P2): its product's names; its state now (`SCHEDULED`,
 * `RUNNING`, `ENDED`); a percentage (basis points) or a sale price; its dates and when it was added (ISO
 * 8601); "always wins"; its sizes, by id and by name in the page's language; **All Sizes** - it covers every
 * size of the product switched on in the store now - else "n of m", of those switched on now
 * (`sizesOn` of `productSizesOn`); how many of its sizes were archived since (the size or its product);
 * and the names of its sizes with no price now - a scheduled one can't be changed until they have one.
 */
final readonly class SaleRow
{
    /**
     * @param  list<string>  $variantIds
     * @param  list<string>  $sizeNames  in the order of `variantIds`
     * @param  list<string>  $unpricedSizeNames
     */
    public function __construct(
        public string $id,
        public string $productId,
        public string $productNameAr,
        public ?string $productNameEn,
        public string $state,
        public ?int $percentBasisPoints,
        public ?MoneyData $salePrice,
        public string $startsAt,
        public ?string $endsAt,
        public ?string $createdAt,
        public bool $alwaysWins,
        public array $variantIds,
        public array $sizeNames,
        public bool $allSizes,
        public int $sizesOn,
        public int $productSizesOn,
        public int $archivedSince,
        public array $unpricedSizeNames,
    ) {}
}
