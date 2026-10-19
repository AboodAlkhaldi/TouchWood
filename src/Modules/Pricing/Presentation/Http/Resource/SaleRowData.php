<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One offer on Offers (P2).
 */
#[TypeScript]
final class SaleRowData extends Data
{
    /**
     * @param  list<string>  $variantIds
     * @param  list<string>  $sizeNames
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
