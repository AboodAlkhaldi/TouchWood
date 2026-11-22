<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * C3 · A carrier (shipping.md §4.1 parts 1 and 2): its details; its prices, cheapest first, with the version
 * read, sent back with every save (R10); and the store-wide free-above amount, which overrides its own (R11).
 */
#[TypeScript]
final class CarrierPage extends Data
{
    /**
     * @param  string  $countryName  the carrier's country, in the page's language
     * @param  list<PriceGroupData>  $prices
     */
    public function __construct(
        public CarrierData $carrier,
        public string $countryName,
        public bool $mayManage,
        public string $storeCode,
        public string $storeName,
        public bool $storeIsActive,
        public array $prices = [],
        public string $pricesVersion = '',
        public ?MoneyData $storeFreeAbove = null,
        public bool $mayManagePrices = false,
        public string $currency = '',
        public int $decimals = 2,
    ) {}
}
