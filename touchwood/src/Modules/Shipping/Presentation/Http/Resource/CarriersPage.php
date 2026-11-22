<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * C1 · Shipping › Carriers (shipping.md §4.1 part 1): a section per country the store ships to, its own
 * first (Q2). `mayManage` offers Add, Edit, the switch, the drag handles and Delete; without it the
 * page is read-only and none of them is shown (Q12). Part 2: the store-wide free-above amount (R12; null =
 * off), offered to change to whoever may manage prices (`mayManagePrices`, R14), in the store's currency.
 */
#[TypeScript]
final class CarriersPage extends Data
{
    /**
     * @param  list<CarrierCountryData>  $countries
     * @param  list<StoreOptionData>  $stores
     */
    public function __construct(
        public array $countries,
        public bool $mayManage,
        public string $storeCode,
        public string $storeName,
        public bool $storeIsActive,
        public array $stores,
        public ?MoneyData $storeFreeAbove = null,
        public bool $mayManagePrices = false,
        public string $currency = '',
        public int $decimals = 2,
    ) {}
}
