<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * M4 · Marketing › Gift Levels (promotions.md §4.1): the store's levels, highest first (Q10); the sizes
 * the picker's search found, only when a manager searched; the currency and its decimals for the
 * amount box. `mayManage` offers New Level…, Edit… and the switch (Q16); `mayManageStock` the stock tick
 * box, out of reach with its reason without it (Q11 (c)).
 */
#[TypeScript]
final class GiftLevelsPage extends Data
{
    /**
     * @param  list<GiftLevelData>  $levels
     * @param  list<SizeChoiceData>  $sizes
     * @param  list<StoreOptionData>  $stores
     */
    public function __construct(
        public array $levels,
        public array $sizes,
        public string $currency,
        public int $exponent,
        public bool $mayManage,
        public bool $mayManageStock,
        public string $storeCode,
        public string $storeName,
        public bool $storeIsActive,
        public string $storeTimezone,
        public array $stores,
    ) {}
}
