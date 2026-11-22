<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A product as the products list shows it (catalog.md §4.4 S8). With a store chosen, its Price and Can't
 * Order (pricing.md §9.4 Q5), from Catalog's own facts: never read from Pricing or Inventory.
 */
#[TypeScript]
final class ProductRowData extends Data
{
    /**
     * @param  list<string>  $codes
     * @param  string|null  $photo  its card photo's thumbnail address, once its sizes are ready
     * @param  int  $variants  its variants not archived
     * @param  list<string>  $onIn  the codes of the stores the reader covers where it is on
     * @param  string|null  $storeState  with a store chosen: ON, OFF, NOT_CHOSEN or NOT_AVAILABLE
     * @param  int  $storeVariantsOn  with a store chosen: how many of its variants are on there
     * @param  MoneyData|null  $priceFrom  with a store chosen: the lowest one-piece price now of its sizes switched on there ("From 120.00 SAR")
     * @param  string|null  $cantOrder  with a store chosen and a size switched on there: how many of them can't be ordered now ("2 of 5 sizes")
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public ?string $nameEn,
        public array $codes,
        public string $stage,
        public string $brandNameAr,
        public string $brandNameEn,
        public ?string $categoryNameAr,
        public ?string $categoryNameEn,
        public ?string $photo,
        public int $variants,
        public array $onIn,
        public ?string $storeState,
        public int $storeVariantsOn,
        public ?MoneyData $priceFrom = null,
        public ?string $cantOrder = null,
    ) {}
}
